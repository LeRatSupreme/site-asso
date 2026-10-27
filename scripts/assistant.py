#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
T4 — Pont d'orchestration de l'assistant AEIC (llama-server ↔ endpoints outils).

Mission T4-F (spécification docs-plans/T4_ASSISTANT_QWEN3.md §2.3/§2.4,
contraintes /tmp/t4_briefs/00_CONTRAINTES.md) : boucle de tool-calling du
modèle local Qwen3-4B vers les 3 endpoints LECTURE SEULE créés en T4-E
(AssistantToolsController) :

    GET https://…/internal/assistant/ventes_periode?debut=…&fin=…
    GET https://…/internal/assistant/stock_produit?nom=…
    GET https://…/internal/assistant/produits_a_commander

Le modèle CHOISIT l'outil et FORMULE la réponse ; il ne calcule ni n'invente
aucun chiffre (spéc §0) — toute réponse affiche ses sources (outil + paramètres).

RUNBOOK
-------
Environnement (une seule fois — aucune dépendance tierce, stdlib uniquement) :

    python3 -m venv ~/AEIC/.venv-assistant
    ~/AEIC/.venv-assistant/bin/pip install -r scripts/requirements-assistant.txt

Usage :

    ~/AEIC/.venv-assistant/bin/python3 scripts/assistant.py "question"
    … --json        # sortie brute de la session complète (JSON)
    … --demo        # 5 questions types + rapport comparatif (logs/)
    … --think       # active le mode « réflexion » de Qwen3 (≈ x5 plus lent)

(Le script fonctionne aussi avec le python3 système : rien à installer.)

SÉCURITÉ (contraintes T4 n°3-4, spéc §2.5)
------------------------------------------
  - JETON : ASSISTANT_TOKEN lu dans config.env — jamais en dur, jamais
    affiché, et JAMAIS transmis au modèle : seul ce pont l'ajoute en en-tête
    « X-Assistant-Token ».
  - WHITELIST EN DUR : exactement 3 outils, requêtes GET uniquement,
    destination 127.0.0.1:443 avec SNI/Host « asso.aremond.ovh » (vhost
    dédié + certificat vérifié : trafic strictement local, chiffé et
    authentifié). Timeout endpoint : 10 s.
  - LLM : http://127.0.0.1:8080 UNIQUEMENT (service systemd --user llm-aeic).
  - PAS de shell, PAS de SQL généré, AUCUNE écriture hors logs/ (journal
    JSONL logs/assistant.jsonl + rapports démo logs/assistant_demo_*.md).
  - Timeout global : 120 s par question. Max 4 appels d'outils. Résultat
    d'outil tronqué à ~4 000 caractères (protège le contexte KV 8192).
  - Erreurs génériques côté utilisateur ; détails en journal.
"""

from __future__ import annotations

import argparse
import datetime as dt
import http.client
import json
import os
import re
import socket
import ssl
import sys
import time
import urllib.parse
from dataclasses import dataclass, field
from pathlib import Path
from zoneinfo import ZoneInfo

# ---------------------------------------------------------------------------
# Constantes de configuration (tout en dur ici est LOCAL, jamais secret)
# ---------------------------------------------------------------------------

RACINE = Path(__file__).resolve().parent.parent

#: Fichier de configuration AEIC (jeton, hors git via .gitignore).
FICHIER_CONFIG = RACINE / "config.env"

#: Journal des sessions (corpus futur QLoRA, spéc §3.1) — répertoire ignoré.
JOURNAL_JSONL = RACINE / "logs" / "assistant.jsonl"

#: llama-server (service systemd --user « llm-aeic », localhost uniquement).
LLM_HOTE = "127.0.0.1"
LLM_PORT = 8080
LLM_MODELE = "Qwen3-4B"

#: Endpoints outils AEIC : connexion BOUCLE Locale (127.0.0.1) avec SNI du
#: vhost asso.aremond.ovh — Apache route le bon VirtualHost ET le certificat
#: Let's Encrypt est vérifié (server_hostname = domaine).
AEIC_DOMAINE = "asso.aremond.ovh"
AEIC_CHEMIN = "/internal/assistant/"

#: Budgets (brief T4-F).
TIMEOUT_GLOBAL_S = 120.0   # par question
TIMEOUT_OUTIL_S = 10.0     # par appel d'endpoint
TIMEOUT_LLM_MIN_S = 30.0   # par complétion LLM (au minimum)
MAX_APPELS_OUTILS = 4      # par question
MAX_TOURS_LLM = 6          # complétions LLM au total (garde-fou)
MAX_TOKENS_LLM = 256       # par complétion (≈ 40 s max à 6 tok/s mesurés)
TRONCATURE_CARACTERES = 4000
TRONCATURE_PRODUITS_MAX = 8    # produits conservés dans une liste tronquée

CODE_SORTIE_USAGE = 2
CODE_SORTIE_LLM_INDISPONIBLE = 3
CODE_SORTIE_JETON_MANQUANT = 4
CODE_SORTIE_ECHEC = 5

#: Dates ISO strictes AAAA-MM-JJ (le contrôleur refait checkdate côté serveur).
MOTIF_DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


# ---------------------------------------------------------------------------
# Erreurs propres
# ---------------------------------------------------------------------------


class ServiceIndisponible(Exception):
    """llama-server ne répond pas (message utilisateur clair)."""


class JetonManquant(Exception):
    """ASSISTANT_TOKEN absent de config.env (les outils sont fail-closed)."""


# ---------------------------------------------------------------------------
# Configuration : jeton serveur (jamais affiché, jamais au modèle)
# ---------------------------------------------------------------------------


def charger_jeton() -> str:
    """Lit ASSISTANT_TOKEN dans config.env (ou la variable d'environnement).

    Le jeton n'est JAMAIS affiché ni journalisé — une variable
    d'environnement permet la surcharge sans toucher au fichier.
    """
    depuis_env = os.environ.get("ASSISTANT_TOKEN", "").strip()
    if depuis_env:
        return depuis_env

    if not FICHIER_CONFIG.is_file():
        raise JetonManquant(
            f"Fichier {FICHIER_CONFIG} introuvable — jeton ASSISTANT_TOKEN "
            "impossible à lire (outils fail-closed)."
        )

    for ligne in FICHIER_CONFIG.read_text(encoding="utf-8").splitlines():
        ligne = ligne.strip()
        if ligne.startswith("ASSISTANT_TOKEN="):
            jeton = ligne.split("=", 1)[1].strip().strip('"').strip("'")
            if not jeton:
                break
            return jeton

    raise JetonManquant(
        "ASSISTANT_TOKEN absent ou vide dans config.env — les endpoints outils "
        "refusent toute requête (fail-closed)."
    )


# ---------------------------------------------------------------------------
# Définitions d'outils (contrat T4-E — descriptions françaises, schémas stricts)
# ---------------------------------------------------------------------------


def definitions_outils() -> list[dict]:
    """Définitions OpenAI-style des 3 endpoints (spéc §2.3 : schémas stricts)."""
    return [
        {
            "type": "function",
            "function": {
                "name": "ventes_periode",
                "description": (
                    "Ventes d'une période : CA total, nombre de transactions, "
                    "quantité et top produits. Bornes incluses (AAAA-MM-JJ, à "
                    "calculer depuis la date du jour ; semaine = 7 derniers jours)."
                ),
                "parameters": {
                    "type": "object",
                    "properties": {
                        "debut": {
                            "type": "string",
                            "description": "Premier jour de la période, format AAAA-MM-JJ.",
                        },
                        "fin": {
                            "type": "string",
                            "description": "Dernier jour de la période (inclus), format AAAA-MM-JJ.",
                        },
                    },
                    "required": ["debut", "fin"],
                },
            },
        },
        {
            "type": "function",
            "function": {
                "name": "stock_produit",
                "description": (
                    "Stock théorique actuel d'un produit par son nom (ex. « Coca "
                    "Cola ») : stock, date du dernier comptage, et 5 candidats "
                    "proches si introuvable. Stock négatif = anomalie à signaler."
                ),
                "parameters": {
                    "type": "object",
                    "properties": {
                        "nom": {
                            "type": "string",
                            "description": "Nom du produit tel que saisi (1 à 255 caractères).",
                        },
                    },
                    "required": ["nom"],
                },
            },
        },
        {
            "type": "function",
            "function": {
                "name": "produits_a_commander",
                "description": (
                    "Liste de réapprovisionnement conseillée pour couvrir 7 jours "
                    "(analyse des 14 derniers jours) : stock, autonomie, quantité "
                    "à commander arrondie au pack, coût. Sans paramètre."
                ),
                "parameters": {"type": "object", "properties": {}},
            },
        },
    ]


#: Whitelist EN DUR des outils exécutables : nom → paramètres d'URL autorisés.
OUTILS_AUTORISES: dict[str, list[str]] = {
    "ventes_periode": ["debut", "fin"],
    "stock_produit": ["nom"],
    "produits_a_commander": [],
}


def message_systeme() -> str:
    """Consigne système compacte (règles anti-invention, spéc §0 et risque R4).

    Volontairement courte : le préfill CPU coûte ~22 tok/s mesurés — chaque
    token de consigne est payé sur CHAQUE tour de CHAQUE question.
    """
    aujourdhui = dt.date.today().isoformat()
    return (
        "Tu es l'assistant de l'association AEIC (kiosque d'un lycée). "
        f"Aujourd'hui : {aujourdhui} (Europe/Paris). Réponds en français, "
        "en 10 lignes maximum.\n"
        "RÈGLES :\n"
        "1. Tout chiffre vient d'un outil, jamais calculé ni inventé : appelle "
        "l'outil adapté AVANT de répondre, même si tu crois connaître la réponse. "
        "N'annonce JAMAIS ton intention (« je dois appeler… ») : appelle "
        "directement l'outil, sans phrase préalable.\n"
        "2. Cite la source de chaque chiffre (outil appelé, période ou produit).\n"
        "3. Outil trouve=false : ne devine pas, propose les candidats listés.\n"
        "4. produits_a_commander peut être tronqué (liste_tronquee=true) : "
        "signale-le et résume aux 10 produits prioritaires avec le coût total, "
        "sans tout énumérer.\n"
        "5. Maximum 4 appels d'outils par question, un par outil."
    )


# ---------------------------------------------------------------------------
# Transports HTTP (stdlib uniquement)
# ---------------------------------------------------------------------------


class ConnexionOutils(http.client.HTTPSConnection):
    """HTTPS vers 127.0.0.1 avec SNI/Host du domaine AEIC.

    - destination FORCÉE à 127.0.0.1 : le trafic ne quitte jamais le VPS ;
    - server_hostname = domaine : Apache sélectionne le bon VirtualHost
      (SNI) ET le certificat Let's Encrypt est vérifié (aucun verify=False).
    """

    def connect(self) -> None:  # pragma: no cover — transport réseau
        brut = socket.create_connection(("127.0.0.1", self.port), timeout=self.timeout)
        contexte = ssl.create_default_context()
        self.sock = contexte.wrap_socket(brut, server_hostname=self.host)


def requete_llm(
    messages: list[dict], deadline: float, reflexion: bool
) -> tuple[dict, dict]:
    """Une complétion /v1/chat/completions vers llama-server (localhost).

    Renvoie (message_de_reponse, meta) où meta = {ms, completion_tokens}.
    Lève ServiceIndisponible avec message clair si le service est down.
    """
    charge: dict = {
        "model": LLM_MODELE,
        "messages": messages,
        "tools": definitions_outils(),
        "tool_choice": "auto",
        "max_tokens": MAX_TOKENS_LLM,
        "stream": False,
    }
    if reflexion:
        # Recommandations Qwen3 mode réflexion.
        charge["temperature"] = 0.6
        charge["top_p"] = 0.95
    else:
        # Mode direct (défaut du pont : ~x5 plus rapide, tool-calling validé).
        charge["temperature"] = 0.7
        charge["top_p"] = 0.8
        charge["chat_template_kwargs"] = {"enable_thinking": False}

    corps = json.dumps(charge, ensure_ascii=False).encode("utf-8")
    reste = deadline - time.monotonic()
    timeout = max(TIMEOUT_LLM_MIN_S, reste)

    debut = time.monotonic()
    try:
        conn = http.client.HTTPConnection(LLM_HOTE, LLM_PORT, timeout=timeout)
        conn.request(
            "POST",
            "/v1/chat/completions",
            body=corps,
            headers={"Content-Type": "application/json"},
        )
        reponse = conn.getresponse()
        brut = reponse.read()
        statut = reponse.status
        conn.close()
    except TimeoutError as e:
        # Génération trop lente (budget épuisé en cours de complétion) :
        # échec honnête, pas d'indisponibilité de service.
        raise TimeoutError(
            "la génération du modèle n'a pas terminé dans le budget imparti "
            f"({TIMEOUT_GLOBAL_S:.0f} s au total) — réessaie ou découpe la question"
        ) from e
    except OSError as e:
        raise ServiceIndisponible(
            "Le service de l'assistant (llama-server) ne répond pas sur "
            f"{LLM_HOTE}:{LLM_PORT} — relance : systemctl --user start llm-aeic "
            f"(détail journalisé : {type(e).__name__})"
        ) from e

    if statut != 200:
        raise ServiceIndisponible(
            f"Le service de l'assistant (llama-server) a répondu {statut} — "
            "consulter : journalctl --user -u llm-aeic -n 30"
        )

    donnees = json.loads(brut.decode("utf-8"))
    choix = donnees["choices"][0]
    message = choix["message"]
    meta = {
        "ms": round((time.monotonic() - debut) * 1000),
        "completion_tokens": donnees.get("usage", {}).get("completion_tokens"),
        "finish_reason": choix.get("finish_reason"),
    }
    # Champ content : ne garder que la réponse finale (la réflexion éventuelle
    # arrive dans reasoning_content, jamais montrée à l'utilisateur).
    if isinstance(message.get("content"), str):
        message["content"] = re.sub(r"<think>.*?</think>", "", message["content"], flags=re.S).strip()
    return message, meta


def requete_outil(nom: str, arguments: dict, jeton: str, deadline: float) -> dict:
    """Exécute UN appel d'outil whitelisté vers l'app AEIC (GET, 10 s).

    Renvoie {statut_http, corps} — le corps est le JSON de l'endpoint ou un
    message d'erreur propre (réseau/timeout). Jamais d'exception vers l'appelant.
    """
    if nom not in OUTILS_AUTORISES:
        return {
            "statut_http": 0,
            "corps": (
                f"Erreur : outil « {nom} » inconnu. Outils disponibles : "
                + ", ".join(sorted(OUTILS_AUTORISES))
                + "."
            ),
        }

    parametres_url = [
        (cle, str(arguments[cle]))
        for cle in OUTILS_AUTORISES[nom]
        if arguments.get(cle) is not None
    ]
    url = AEIC_CHEMIN + nom
    if parametres_url:
        url += "?" + urllib.parse.urlencode(parametres_url)

    try:
        conn = ConnexionOutils(AEIC_DOMAINE, 443, timeout=TIMEOUT_OUTIL_S)
        conn.request(
            "GET",
            url,
            headers={
                "X-Assistant-Token": jeton,
                "Accept": "application/json",
            },
        )
        reponse = conn.getresponse()
        corps = reponse.read().decode("utf-8", errors="replace")
        statut = reponse.status
        conn.close()
        return {"statut_http": statut, "corps": corps}
    except OSError as e:
        return {
            "statut_http": 0,
            "corps": f"Erreur : l'app AEIC ({AEIC_DOMAINE}, en local) n'a pas répondu en {TIMEOUT_OUTIL_S:.0f} s ({type(e).__name__}).",
        }


# ---------------------------------------------------------------------------
# Validation client des arguments + troncature intelligente des résultats
# ---------------------------------------------------------------------------


def valider_arguments(nom: str, arguments: dict) -> tuple[dict | None, str | None]:
    """Validation minimale côté pont ; le contrôleur revalide tout côté serveur.

    Renvoie (arguments_propres, None) ou (None, message_d_erreur) — l'erreur
    est réinjectée au modèle qui se corrige (spéc §2.3, risque R3).
    """
    if nom == "ventes_periode":
        debut = str(arguments.get("debut") or "").strip()
        fin = str(arguments.get("fin") or "").strip()
        if not MOTIF_DATE.match(debut) or not MOTIF_DATE.match(fin):
            return None, (
                "Erreur : ventes_periode exige debut et fin au format AAAA-MM-JJ "
                "(ex. 2026-10-21 et 2026-10-27)."
            )
        return {"debut": debut, "fin": fin}, None

    if nom == "stock_produit":
        libelle = str(arguments.get("nom") or "").strip()
        if not libelle or len(libelle) > 255:
            return None, (
                "Erreur : stock_produit exige un paramètre nom de 1 à 255 caractères."
            )
        return {"nom": libelle}, None

    if nom == "produits_a_commander":
        return {}, None

    return None, f"Erreur : outil « {nom} » inconnu."


def tronquer_resultat(corps: str) -> tuple[str, bool]:
    """Tronque un résultat d'outil à ~4 000 caractères en restant JSON valide.

    Cas produits_a_commander : la liste « produits » est compactée (les
    premiers arrivent triés par quantité à commander décroissante, côté
    contrôleur) et complétée des champs « liste_tronquee » / 
    « produits_non_listes » pour que le modèle sache qu'il ne voit pas tout.
    Tout autre corps : coupe franche avec marqueur.
    Renvoie (texte_final, a_ete_tronque).
    """
    if len(corps) <= TRONCATURE_CARACTERES:
        return corps, False

    try:
        donnees = json.loads(corps)
    except json.JSONDecodeError:
        return corps[:TRONCATURE_CARACTERES] + " …[tronqué à 4000 caractères]", True

    if isinstance(donnees, dict) and isinstance(donnees.get("produits"), list):
        produits = donnees["produits"]
        conserves: list = []
        # Objectif : ~2 800 caractères de JSON compact, TRONCATURE_PRODUITS_MAX max.
        for produit in produits[:TRONCATURE_PRODUITS_MAX]:
            candidat = conserves + [produit]
            taille_c = len(
                json.dumps({**donnees, "produits": candidat}, ensure_ascii=False)
            )
            if taille_c > 2800 and conserves:
                break
            conserves = candidat
        if len(conserves) == len(produits):
            return json.dumps({**donnees, "produits": conserves}, ensure_ascii=False), True
        compacts = {
            **donnees,
            "produits": conserves,
            "liste_tronquee": True,
            "produits_non_listes": len(produits) - len(conserves),
        }
        return json.dumps(compacts, ensure_ascii=False), True

    return corps[:TRONCATURE_CARACTERES] + " …[tronqué à 4000 caractères]", True


# ---------------------------------------------------------------------------
# Boucle d'orchestration (question → LLM → outils → réponse + sources)
# ---------------------------------------------------------------------------


@dataclass
class Session:
    """Une question complète : tours, appels d'outils, réponse, timing."""

    question: str
    tours: list[dict] = field(default_factory=list)
    appels: list[dict] = field(default_factory=list)
    reponse: str = ""
    sources: list[str] = field(default_factory=list)
    ok: bool = False
    erreur: str = ""
    reflexion: bool = False
    chiffre_sans_source: bool = False

    def duree_ms(self) -> int:
        return sum(t.get("ms", 0) for t in self.tours) + sum(
            a.get("ms", 0) for a in self.appels
        )


def poser_question(question: str, jeton: str, reflexion: bool = False) -> Session:
    """Boucle tool-calling : max 4 appels d'outils, timeout global 120 s.

    Au-delà des limites ou du budget : réponse d'échec honnête (spéc §2.3,
    « pas de rattrapage inventé ») — jamais de chiffre fabriqué.
    """
    session = Session(question=question, reflexion=reflexion)
    deadline = time.monotonic() + TIMEOUT_GLOBAL_S
    messages: list[dict] = [
        {"role": "system", "content": message_systeme()},
        {"role": "user", "content": question},
    ]

    try:
        for _ in range(MAX_TOURS_LLM):
            if time.monotonic() >= deadline:
                raise TimeoutError("budget global de 120 s épuisé")

            message, meta = requete_llm(messages, deadline, reflexion)
            messages.append(message)
            session.tours.append(
                {
                    "role": "assistant",
                    "ms": meta["ms"],
                    "completion_tokens": meta["completion_tokens"],
                    "finish_reason": meta["finish_reason"],
                }
            )

            appels_recus = message.get("tool_calls") or []
            if not appels_recus:
                # Réponse finale : le contenu du modèle, tel quel.
                session.reponse = message.get("content") or ""
                session.ok = bool(session.reponse.strip())
                if not session.ok:
                    session.erreur = "réponse finale vide"
                # Audit anti-invention (R4) : chiffre sans aucun appel d'outil.
                if session.ok and not session.appels and re.search(r"\d", session.reponse):
                    session.chiffre_sans_source = True
                return session

            if len(session.appels) + len(appels_recus) > MAX_APPELS_OUTILS:
                raise RuntimeError(
                    "limite de 4 appels d'outils atteinte — reformulez la question "
                    "ou découpez-la"
                )

            for appel in appels_recus:
                identifiant = appel.get("id") or f"appel_{len(session.appels) + 1}"
                fonction = appel.get("function", {})
                nom = str(fonction.get("name") or "")
                brut_arguments = str(fonction.get("arguments") or "{}")

                statut = 0
                try:
                    arguments = json.loads(brut_arguments or "{}")
                    if not isinstance(arguments, dict):
                        raise ValueError("arguments non objet")
                    arguments, erreur_validation = valider_arguments(nom, arguments)
                    if erreur_validation is not None:
                        # Arguments invalides : erreur réinjectée, le modèle se
                        # corrige au tour suivant (spéc §2.3, risque R3).
                        resultats = erreur_validation
                    else:
                        debut_appel = time.monotonic()
                        reponse_outil = requete_outil(nom, arguments, jeton, deadline)
                        statut = reponse_outil["statut_http"]
                        resultats, _tronque = tronquer_resultat(reponse_outil["corps"])
                        session.appels.append(
                            {
                                "outil": nom,
                                "arguments": arguments,
                                "statut_http": statut,
                                "ms": round((time.monotonic() - debut_appel) * 1000),
                                "resultat": resultats,
                            }
                        )
                        if statut == 200:
                            session.sources.append(_source(nom, arguments))
                except (json.JSONDecodeError, ValueError):
                    resultats = (
                        "Erreur : arguments invalides (JSON attendu, ex. "
                        '{"nom": "Coca Cola"}).'
                    )

                # TOUJOURS injecter le résultat (succès, erreur HTTP ou erreur
                # d'arguments) : c'est ce message « tool » qui boucle la session.
                messages.append(
                    {"role": "tool", "tool_call_id": identifiant, "content": resultats}
                )

    except ServiceIndisponible as e:
        session.erreur = str(e)
    except TimeoutError as e:
        session.erreur = f"Délai dépassé : {e}"
    except RuntimeError as e:
        session.erreur = str(e)
    except OSError as e:
        session.erreur = f"Erreur réseau : {type(e).__name__}"

    return session


def _source(nom: str, arguments: dict) -> str:
    """Formate une source lisible : outil(paramètre=valeur, …)."""
    if not arguments:
        return nom
    detail = ", ".join(f"{cle}={valeur}" for cle, valeur in arguments.items())
    return f"{nom}({detail})"


# ---------------------------------------------------------------------------
# Journalisation (JSONL — futur corpus QLoRA, spéc §3.1)
# ---------------------------------------------------------------------------


def journaliser(session: Session) -> None:
    """Ajoute la session au journal JSONL (hors jeton, hors réflexion modèle)."""
    try:
        JOURNAL_JSONL.parent.mkdir(parents=True, exist_ok=True)
        entree = {
            "horodatage": dt.datetime.now(ZoneInfo("Europe/Paris")).isoformat(timespec="seconds"),
            "question": session.question,
            "ok": session.ok,
            "duree_ms": session.duree_ms(),
            "reflexion": session.reflexion,
            "chiffre_sans_source": session.chiffre_sans_source,
            "appels_outils": session.appels,
            "tours_llm": session.tours,
            "reponse": session.reponse,
            "erreur": session.erreur,
        }
        with JOURNAL_JSONL.open("a", encoding="utf-8") as sortie:
            sortie.write(json.dumps(entree, ensure_ascii=False) + "\n")
    except OSError as e:  # journal best-effort : jamais bloquant
        print(f"(journalisation impossible : {type(e).__name__})", file=sys.stderr)


# ---------------------------------------------------------------------------
# Modes d'exécution
# ---------------------------------------------------------------------------


def afficher_session(session: Session) -> None:
    """Sortie lisible par défaut : réponse + SOURCES (exigence spec §0)."""
    if not session.ok:
        print(f"Échec : {session.erreur or 'réponse indisponible'}")
    else:
        print(session.reponse)
        if session.chiffre_sans_source:
            print(
                "\n⚠ La réponse contient des chiffres sans aucun appel d'outil : "
                "à vérifier (règle « jamais de chiffre sans source »)."
            )

    if session.sources:
        print("\nSources : " + " ; ".join(session.sources))
    print(
        f"\n({len(session.appels)} appel(s) d'outil · {len(session.tours)} tour(s) LLM "
        f"· {session.duree_ms() / 1000:.1f} s · journal : {JOURNAL_JSONL.name})"
    )


def mode_demo(jeton: str, reflexion: bool) -> int:
    """Pose les 5 questions types (spéc §1, périmètre P0) et rapporte.

    Écrit un rapport comparatif Markdown dans logs/ (réponse + outils +
    temps par question). Retour 0 si toutes les sessions aboutissent.
    """
    questions = [
        "Comment ça a marché cette semaine ?",
        "Qu'est-ce qu'il faut que je rachète ?",
        "Il me reste combien de Bueno ?",
        "Combien me reste-t-il de Coca Cola ?",
        "Quelles ont été mes ventes entre le 20 et le 26 octobre 2026 ?",
    ]

    horodatage = dt.datetime.now(ZoneInfo("Europe/Paris")).strftime("%Y%m%d_%H%M")
    chemin_rapport = RACINE / "logs" / f"assistant_demo_{horodatage}.md"

    lignes_rapport: list[str] = [
        "# Démo assistant AEIC — 5 questions types (T4-F)",
        "",
        f"Généré le {dt.datetime.now(ZoneInfo('Europe/Paris')).isoformat(timespec='seconds')} "
        f"— modèle {LLM_MODELE} local, réflexion {'activée' if reflexion else 'désactivée'}.",
        "",
        "| # | Question | Outils appelés | Durée | Statut |",
        "|---|----------|----------------|-------|--------|",
    ]

    rapports_reponses: list[str] = []
    reussies = 0
    for indice, question in enumerate(questions, start=1):
        print(f"\n=== Question {indice}/5 : {question}")
        session = poser_question(question, jeton, reflexion)
        journaliser(session)
        afficher_session(session)
        outils = (
            ", ".join(
                f"{a['outil']}({', '.join(str(v) for v in a['arguments'].values())})"
                for a in session.appels
            )
            or "—"
        )
        lignes_rapport.append(
            f"| {indice} | {question} | {outils} | "
            f"{session.duree_ms() / 1000:.1f} s | "
            f"{'OK' if session.ok else 'ÉCHEC (' + session.erreur[:60] + ')'} |"
        )
        rapports_reponses.append(
            f"### {indice}. {question}\n\n"
            + ("Réponse :\n\n" + session.reponse + "\n" if session.ok else f"ÉCHEC : {session.erreur}\n")
            + (f"\nSources : {' ; '.join(session.sources)}\n" if session.sources else "")
            + ("\n⚠ Chiffres sans appel d'outil (à vérifier).\n" if session.chiffre_sans_source else "")
        )
        reussies += int(session.ok)

    lignes_rapport += ["", "## Réponses complètes", "", *rapports_reponses]

    chemin_rapport.parent.mkdir(parents=True, exist_ok=True)
    chemin_rapport.write_text("\n".join(lignes_rapport), encoding="utf-8")
    print(
        f"\n=== Bilan démo : {reussies}/{len(questions)} sessions OK — "
        f"rapport : {chemin_rapport}"
    )
    return 0 if reussies == len(questions) else CODE_SORTIE_ECHEC


def main(argv: list[str] | None = None) -> int:
    parseur = argparse.ArgumentParser(
        prog="assistant.py",
        description=(
            "Assistant AEIC : pose une question métier (stock, ventes, commandes) ; "
            "le modèle local choisit l'outil AEIC adapté et formule la réponse sourcée."
        ),
    )
    parseur.add_argument(
        "question",
        nargs="*",
        help="question en français (entre guillemets)",
    )
    parseur.add_argument(
        "--json",
        action="store_true",
        help="sortie brute JSON de la session complète",
    )
    parseur.add_argument(
        "--demo",
        action="store_true",
        help="pose les 5 questions types et rédige un rapport comparatif (logs/)",
    )
    parseur.add_argument(
        "--think",
        action="store_true",
        help="active le mode réflexion de Qwen3 (plus lent, ≈ x5)",
    )
    arguments = parseur.parse_args(argv)

    if arguments.demo and arguments.question:
        parseur.error("--demo ignore la question ; l'un ou l'autre.")
    if not arguments.demo and not arguments.question:
        parseur.error("une question est requise (ou --demo).")
    reflexion = arguments.think

    try:
        jeton = charger_jeton()
    except JetonManquant as e:
        print(f"Configuration incomplète : {e}", file=sys.stderr)
        return CODE_SORTIE_JETON_MANQUANT

    if arguments.demo:
        return mode_demo(jeton, reflexion)

    question = " ".join(arguments.question)
    session = poser_question(question, jeton, reflexion)
    journaliser(session)
    if arguments.json:
        print(
            json.dumps(
                {
                    "question": session.question,
                    "ok": session.ok,
                    "reponse": session.reponse,
                    "sources": session.sources,
                    "appels_outils": session.appels,
                    "tours_llm": session.tours,
                    "duree_ms": session.duree_ms(),
                    "erreur": session.erreur,
                },
                ensure_ascii=False,
                indent=2,
            )
        )
    else:
        afficher_session(session)

    if not session.ok:
        # Service down = code 3 (le message de relance a déjà été affiché
        # par afficher_session et la session est journalisée), autre échec = 5.
        if session.erreur.startswith("Le service de l'assistant"):
            return CODE_SORTIE_LLM_INDISPONIBLE
        return CODE_SORTIE_ECHEC
    return 0


if __name__ == "__main__":
    sys.exit(main())
