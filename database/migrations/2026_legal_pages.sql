-- ============================================================
--  AEIC — Pages juridiques complètes (Mentions légales,
--  Politique de confidentialité RGPD, CGU)
--  À appliquer sur une base existante :
--    mysql -u aeic -p aeic < database/migrations/2026_legal_pages.sql
--  Pensez à remplacer les [À COMPLÉTER] via l'admin (Pages CMS).
-- ============================================================

UPDATE pages SET title = 'Mentions légales',
meta_title = 'Mentions légales — AEIC',
meta_description = 'Mentions légales du site de l''AEIC (éditeur, hébergeur, propriété intellectuelle).',
content = '<h2>1. Éditeur du site</h2>
<p>Le présent site <strong>https://asso.aremond.ovh/</strong> (ci-après « le Site ») est édité par :</p>
<ul>
<li><strong>AEIC — Association Étudiante Informatique de Calais</strong>, association étudiante régie par la loi du 1<sup>er</sup> juillet 1901.</li>
<li>Siège social : [ADRESSE DU SIÈGE SOCIAL — ex. Campus Universitaire, 101 rue du Professeur Lancereaux, 62228 Calais].</li>
<li>Numéro RNA (Réseau National des Associations) : [N° RNA W621-XXXXXX].</li>
<li>Numéro SIRET : [N° SIRET — si applicable].</li>
<li>Adresse e-mail : <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a>.</li>
</ul>

<h2>2. Responsable de la publication</h2>
<p>Le responsable de la publication est le président de l''association en exercice, à savoir :</p>
<ul>
<li>[PRÉNOM NOM DU PRÉSIDENT], Président de l''AEIC.</li>
<li>Contact : <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a>.</li>
</ul>

<h2>3. Développement et maintenance</h2>
<p>Le Site a été développé et est maintenu par les membres étudiants de l''AEIC, dans le cadre d''un projet associatif bénévole.</p>

<h2>4. Hébergement</h2>
<p>Le Site est hébergé par :</p>
<ul>
<li><strong>OVH SAS</strong></li>
<li>2 rue Kellermann — 59100 Roubaix — France</li>
<li>Forme sociale : SAS au capital de 10 174 560 €</li>
<li>RCS Lille Métropole 424 761 419 00045</li>
<li>Code APE 6202A — N° TVA : FR 22 424761419</li>
<li>Téléphone : 1007 (Depuis la France)</li>
<li>Site web : <a href="https://www.ovhcloud.com" target="_blank" rel="noopener">www.ovhcloud.com</a></li>
</ul>

<h2>5. Propriété intellectuelle</h2>
<p>L''ensemble des éléments du Site (textes, logos, images, icônes, sons, logiciels, structure du site, charte graphique, code source) est, sauf mention contraire, la propriété exclusive de l''AEIC ou de ses partenaires, et est protégé par le Code de la propriété intellectuelle.</p>
<p>Toute reproduction, représentation, modification, publication, adaptation, totale ou partielle, des éléments du Site, quel que soit le moyen ou le procédé utilisé, est interdite sauf autorisation écrite préalable de l''AEIC (à demander à calais.aeic@gmail.com).</p>
<p>Toute exploitation non autorisée du Site ou de l''un quelconque de ses éléments sera considérée comme constitutive d''une contrefaçon et poursuivie conformément aux dispositions des articles L.335-2 et suivants du Code de la propriété intellectuelle.</p>

<h2>6. Marques</h2>
<p>Les dénominations sociales, marques, logos et signes distinctifs cités sur le Site sont la propriété de leurs détenteurs respectifs. Leur utilisation sans autorisation écrite préalable est interdite.</p>

<h2>7. Liens hypertextes</h2>
<p>Le Site peut contenir des liens hypertextes vers d''autres sites. L''AEIC n''exerce aucun contrôle sur ces sites et décline toute responsabilité quant à leur contenu. La création de liens vers le Site nécessite l''autorisation préalable et écrite de l''AEIC.</p>

<h2>8. Responsabilité</h2>
<p>L''AEIC s''efforce de fournir des informations exactes et de maintenir le Site accessible. Toutefois, l''AEIC ne saurait être tenue responsable :</p>
<ul>
<li>des erreurs, omissions ou indisponibilités du Site ;</li>
<li>de la présence éventuelle de virus sur le Site ;</li>
<li>des dommages directs ou indirects, matériels ou immatériels, résultant de l''utilisation du Site, de l''impossibilité d''y accéder ou de la fiabilité des informations qu''il contient.</li>
</ul>
<p>L''AEIC se réserve le droit de modifier, suspendre ou interrompre le Site, totalement ou partiellement, à tout moment, sans préavis.</p>

<h2>9. Protection des données personnelles</h2>
<p>Le traitement des données personnelles fait l''objet d''une <strong>politique de confidentialité</strong> dédiée, accessible à l''adresse : <a href="/privacy">/privacy</a>.</p>

<h2>10. Cookies</h2>
<p>Le Site utilise un cookie strictement nécessaire à son fonctionnement (session de connexion). Le détail est fourni dans la politique de confidentialité.</p>

<h2>11. Droit applicable et règlement des litiges</h2>
<p>Le présent Site et les présentes mentions légales sont régis par le droit français. En cas de litige, une solution amiable sera recherchée en priorité. À défaut, les tribunaux français seront seuls compétents.</p>
<p>Conformément à l''article L.612-1 du Code de la consommation, le consommateur peut recourir gratuitement à un médiateur de la consommation en vue de la résolution amiable d''un litige.</p>

<h2>12. Crédits</h2>
<p>Site réalisé par les étudiants de l''AEIC. Logo et identité visuelle : AEIC. © 2026 AEIC — Tous droits réservés.</p>'
WHERE slug = 'legal';

UPDATE pages SET title = 'Politique de confidentialité',
meta_title = 'Politique de confidentialité — AEIC',
meta_description = 'Protection des données personnelles (RGPD) : données collectées, finalités, durées, droits.',
content = '<p>L''AEIC — Association Étudiante Informatique de Calais (ci-après « l''Association ») accorde la plus grande importance à la protection des données personnelles. La présente politique, établie conformément au Règlement Général sur la Protection des Données (RGPD — Règlement (UE) 2016/679) et à la loi « Informatique et Libertés » modifiée, décrit comment sont collectées et traitées les données des utilisateurs du site <strong>https://asso.aremond.ovh/</strong> (ci-après « le Site »).</p>

<h2>1. Responsable du traitement</h2>
<p>Le responsable du traitement des données personnelles est :</p>
<ul>
<li><strong>AEIC — Association Étudiante Informatique de Calais</strong></li>
<li>Siège : [ADRESSE DU SIÈGE]</li>
<li>Contact : <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a></li>
<li>Contact « Protection des données » (DPO de fait) : <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a></li>
</ul>

<h2>2. Données collectées, finalités et bases légales</h2>
<table>
<thead><tr><th>Donnée</th><th>Finalité</th><th>Base légale</th><th>Conservation</th></tr></thead>
<tbody>
<tr><td>Prénom, nom</td><td>Identification du membre</td><td>Exécution du contrat d''adhésion</td><td>Durée d''adhésion + 1 an</td></tr>
<tr><td>Adresse e-mail</td><td>Communication, connexion, notifications</td><td>Exécution du contrat / intérêt légitime</td><td>Tant que le compte est actif</td></tr>
<tr><td>Mot de passe (haché)</td><td>Authentification sécurisée</td><td>Sécurité</td><td>Tant que le compte est actif</td></tr>
<tr><td>Avatar / photo</td><td>Profil</td><td>Consentement</td><td>Jusqu''à suppression par l''utilisateur</td></tr>
<tr><td>Inscriptions aux événements + choix (menus…)</td><td>Organisation des événements</td><td>Exécution du contrat</td><td>1 an après l''événement</td></tr>
<tr><td>Commandes cafétéria</td><td>Gestion des commandes</td><td>Obligation légale (comptabilité)</td><td>10 ans (obligation comptable)</td></tr>
<tr><td>Adresse IP, journaux de connexion</td><td>Sécurité, prévention des abus</td><td>Intérêt légitime (sécurité)</td><td>6 à 12 mois</td></tr>
<tr><td>Données de paiement (SumUp)</td><td>Traçabilité des paiements</td><td>Exécution du contrat</td><td>10 ans (comptabilité)</td></tr>
</tbody>
</table>
<p><strong>Important :</strong> l''AEIC ne stocke <em>jamais</em> de données bancaires. Les paiements sont intégralement traités par le prestataire <strong>SumUp</strong>, qui est seul responsable de ces données.</p>

<h2>3. Destinataires des données</h2>
<p>Les données sont destinées aux membres du bureau de l''AEIC habilités (président, trésorier) et, le cas échéant, aux prestataires techniques suivants agissant pour le compte de l''Association :</p>
<ul>
<li><strong>OVH SAS</strong> (hébergement du Site et de la base de données), situé à Roubaix (France) ;</li>
<li><strong>SumUp</strong> (paiements en ligne) ;</li>
<li>le cas échéant, un prestataire d''envoi d''e-mails (ex. Brevo).</li>
</ul>
<p>Aucune donnée n''est vendue ni cédée à des tiers à des fins commerciales.</p>

<h2>4. Transferts hors de l''Union européenne</h2>
<p>Les données sont principalement hébergées sur le territoire de l''Union européenne (France). Le cas échéant, un transfert hors UE ne serait effectué qu''auprès d''un prestataire offrant des garanties appropriées (clauses contractuelles types, décision d''adéquation), conformément au RGPD.</p>

<h2>5. Sécurité</h2>
<p>L''Association met en œuvre des mesures techniques et organisationnelles raisonnables pour protéger les données : connexion chiffrée (HTTPS), mots de passe hachés (bcrypt), requêtes préparées (protection contre les injections SQL), limitation de l''accès aux données aux seules personnes habilitées, journalisation des actions sensibles et sauvegardes régulières.</p>

<h2>6. Vos droits</h2>
<p>Conformément au RGPD, vous disposez des droits suivants sur vos données :</p>
<ul>
<li><strong>Droit d''accès</strong> : obtenir une copie de vos données ;</li>
<li><strong>Droit de rectification</strong> : corriger des données inexactes ;</li>
<li><strong>Droit à l''effacement</strong> (« droit à l''oubli ») : demander la suppression de vos données (sous réserve des obligations comptables) ;</li>
<li><strong>Droit à la portabilité</strong> : recevoir vos données dans un format structuré ;</li>
<li><strong>Droit d''opposition</strong> : vous opposer à un traitement ;</li>
<li><strong>Droit à la limitation</strong> : demander une suspension temporaire du traitement ;</li>
<li><strong>Droit de définir des directives relatives au sort de vos données après votre décès</strong>.</li>
</ul>
<p>Vous pouvez exercer ces droits :</p>
<ul>
<li>depuis votre espace membre (export de vos données, suppression de votre compte) ;</li>
<li>par e-mail à <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a>.</li>
</ul>
<p>L''Association s''engage à répondre dans un délai d''<strong>un mois</strong> (délai légal), éventuellement prolongé de deux mois pour les demandes complexes. Une pièce d''identité pourra vous être demandée afin de vérifier votre identité.</p>

<h2>7. Cookies</h2>
<p>Le Site utilise un <strong>cookie de session strictement nécessaire</strong> à son fonctionnement (maintien de la connexion de l''utilisateur). Ce cookie est exempté de consentement au titre de l''article 82 de la loi « Informatique et Libertés ». Il est supprimé à la fermeture du navigateur ou à la déconnexion.</p>
<table>
<thead><tr><th>Cookie</th><th>Finalité</th><th>Durée</th><th>Consentement</th></tr></thead>
<tbody>
<tr><td>PHPSESSID</td><td>Session de connexion</td><td>Session</td><td>Non requis (strictement nécessaire)</td></tr>
</tbody>
</table>
<p>Le Site ne dépose aucun cookie publicitaire, de mesure d''audience ou de pistage tiers. Le cas échéant, un bandeau de consentement serait préalablement affiché.</p>

<h2>8. Durées de conservation</h2>
<p>Les durées de conservation sont indiquées dans le tableau de la section 2. Les données comptables sont conservées 10 ans conformément aux obligations comptables et fiscales (Code de commerce, art. L.123-22 ; Livre des procédures fiscales, art. L.102 B).</p>

<h2>9. Réclamation auprès de la CNIL</h2>
<p>Si vous estimez, après nous avoir contactés, que vos droits ne sont pas respectés, vous pouvez introduire une réclamation auprès de l''autorité de contrôle :</p>
<ul>
<li><strong>CNIL</strong> — Commission Nationale de l''Informatique et des Libertés</li>
<li>3 place de Fontenoy — TSA 80715 — 75334 Paris CEDEX 07</li>
<li>Site : <a href="https://www.cnil.fr" target="_blank" rel="noopener">www.cnil.fr</a></li>
</ul>

<h2>10. Modification de la politique</h2>
<p>L''AEIC se réserve le droit de modifier la présente politique. Toute modification substantielle sera portée à votre connaissance, le cas échéant par e-mail ou par un avis sur le Site.</p>

<h2>11. Contact</h2>
<p>Pour toute question relative à la protection des données : <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a>.</p>'
WHERE slug = 'privacy';

UPDATE pages SET title = 'Conditions générales d''utilisation',
meta_title = 'CGU — AEIC',
meta_description = 'Conditions générales d''utilisation du site et de l''espace membre de l''AEIC.',
content = '<h2>1. Objet</h2>
<p>Les présentes conditions générales d''utilisation (ci-après « les CGU ») ont pour objet de définir les modalités d''utilisation du site <strong>https://asso.aremond.ovh/</strong> (ci-après « le Site ») et de son espace membre, édité par l''AEIC — Association Étudiante Informatique de Calais.</p>
<p>L''utilisation du Site implique l''acceptation pleine et entière des présentes CGU. Si vous n''êtes pas d''accord, vous êtes invité à ne pas utiliser le Site.</p>

<h2>2. Accès au Site</h2>
<p>L''accès au Site est libre et gratuit pour tout utilisateur disposant d''un accès à Internet. L''AEIC s''efforce de maintenir le Site accessible 24 h/24, mais ne peut garantir une accessibilité absolue. Les frais d''accès et d''utilisation du matériel et du réseau restent à la charge de l''utilisateur.</p>

<h2>3. Inscription et compte membre</h2>
<p>Certaines fonctionnalités (inscriptions aux événements, commandes à la cafétéria, espace membre) requièrent la création d''un compte. Lors de l''inscription, l''utilisateur s''engage à :</p>
<ul>
<li>fournir des informations exactes, complètes et à jour ;</li>
<li>choisir un mot de passe robuste et à en assurer la confidentialité ;</li>
<li>ne pas créer de compte au nom d''un tiers sans son autorisation ;</li>
<li>accepter la politique de confidentialité.</li>
</ul>
<p>L''utilisateur est responsable de l''utilisation de son compte. Toute activité illicite via son compte est de sa responsabilité. En cas de perte, de vol ou d''utilisation frauduleuse, l''utilisateur doit en informer sans délai l''AEIC.</p>

<h2>4. Éligibilité</h2>
<p>L''inscription est principalement destinée aux étudiants du campus de Calais. L''AEIC se réserve le droit de refuser ou de désactiver un compte ne respectant pas les présentes CGU.</p>

<h2>5. Utilisation du Site</h2>
<p>L''utilisateur s''engage à utiliser le Site de manière conforme aux lois et règlements en vigueur et à ne pas porter atteinte aux droits de l''AEIC ou de tiers. Sont notamment interdits :</p>
<ul>
<li>la création de faux comptes ou l''usurpation d''identité ;</li>
<li>la diffusion de contenus illicites, injurieux ou diffamatoires ;</li>
<li>toute tentative de perturbation du fonctionnement du Site (intrusion, piratage, spam) ;</li>
<li>l''extraction massive de données (scraping).</li>
</ul>

<h2>6. Événements et cafétéria</h2>
<p>Les inscriptions aux événements et les commandes à la cafétéria sont soumises aux règles propres de l''AEIC (capacité, stock, paiement). Toute commande validée est due. Les paiements en ligne sont traités par le prestataire SumUp ; les commandes et paiements sont conservés à des fins comptables (durée légale de 10 ans).</p>

<h2>7. Propriété intellectuelle</h2>
<p>Les éléments du Site sont protégés par le droit de la propriété intellectuelle (voir les mentions légales). Toute reproduction sans autorisation est interdite.</p>

<h2>8. Responsabilité</h2>
<p>L''AEIC ne saurait être tenue responsable des dommages directs ou indirects résultant de l''utilisation du Site, notamment en cas d''indisponibilité, d''erreur ou de perte de données. L''AEIC ne répond pas du contenu des sites tiers éventuellement liés.</p>

<h2>9. Données personnelles</h2>
<p>Le traitement des données est décrit dans la <a href="/privacy">politique de confidentialité</a>.</p>

<h2>10. Modification des CGU</h2>
<p>L''AEIC peut modifier les présentes CGU à tout moment. La version applicable est celle en ligne au moment de l''utilisation.</p>

<h2>11. Droit applicable</h2>
<p>Les présentes CGU sont régies par le droit français.</p>

<h2>12. Contact</h2>
<p>Pour toute question : <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a>.</p>'
WHERE slug = 'cgu';
