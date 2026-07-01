-- ============================================================
--  AEIC — Mentions légales complètes (association étudiante)
--  Basé sur la loi LCEN (2004) + RGPD + Code de la propriété intellectuelle
--  mysql -u aeic -p aeic < database/migrations/2026_mentions_legales_final.sql
-- ============================================================

UPDATE pages SET title = 'Mentions légales',
meta_title = 'Mentions légales — AEIC',
meta_description = 'Mentions légales du site de l''AEIC : éditeur, responsable de publication, hébergeur, propriété intellectuelle, responsabilité.',
content = '<h2>Article 1 — Identité de l''éditeur</h2>
<p>Le présent site accessible à l''adresse <strong>https://asso.aremond.ovh/</strong> (ci-après « le Site ») est édité par :</p>
<table style="width:100%;border-collapse:collapse;margin:1rem 0;">
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;width:40%;">Dénomination</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);">AEIC — Association Étudiante Informatique de Calais</td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">Forme juridique</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);">Association loi 1901 (à but non lucratif)</td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">Siège social</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);">IUT de Calais — Département Informatique, 19 Rue Louis David, 62100 Calais</td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">Numéro RNA</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);">[À COMPLÉTER — Répertoire National des Associations]</td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">Numéro SIRET</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);">[À COMPLÉTER — si applicable]</td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">Directeur de la publication</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);">[PRÉNOM NOM DU PRÉSIDENT], Président en exercice</td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">Email de contact</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);"><a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a></td></tr>
</table>

<h2>Article 2 — Responsable de la publication</h2>
<p>Le responsable de la publication est le <strong>Président de l''association AEIC</strong> en exercice, à savoir :</p>
<ul>
<li>[PRÉNOM NOM DU PRÉSIDENT], en sa qualité de Président.</li>
<li>Pour toute question relative au contenu du Site : <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a>.</li>
</ul>

<h2>Article 3 — Hébergement</h2>
<p>Le Site est hébergé par :</p>
<table style="width:100%;border-collapse:collapse;margin:1rem 0;">
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;width:40%;">Hébergeur</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);"><strong>OVH SAS</strong></td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">Adresse</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);">2 rue Kellermann — 59100 Roubaix — France</td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">Forme sociale</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);">SAS au capital de 10 174 560 €</td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">RCS</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);">Lille Métropole 424 761 419 00045</td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">N° TVA</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);">FR 22 424761419</td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">Téléphone</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);">1007 (depuis la France)</td></tr>
<tr><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);font-weight:700;">Site web</td><td style="padding:0.5rem;border:1px solid rgba(255,255,255,0.1);"><a href="https://www.ovhcloud.com" target="_blank" rel="noopener">www.ovhcloud.com</a></td></tr>
</table>

<h2>Article 4 — Propriété intellectuelle</h2>
<h3>4.1 — Contenu du site</h3>
<p>L''ensemble des éléments présents sur le Site (textes, logos, images, icônes, sons, logiciels, structure du site, charte graphique, code source, base de données) est, sauf mention contraire explicitement indiquée, la propriété exclusive de l''AEIC ou de ses partenaires, et est protégé par les dispositions du <strong>Code de la propriété intellectuelle</strong> (articles L.111-1 et suivants).</p>
<p>Toute reproduction, représentation, modification, publication, adaptation, totale ou partielle, des éléments du Site, quel que soit le moyen ou le procédé utilisé, est <strong>interdite</strong> sauf autorisation écrite préalable de l''AEIC (à demander à <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a>).</p>
<p>Toute exploitation non autorisée du Site ou de l''un quelconque de ses éléments sera considérée comme constitutive d''une contrefaçon et poursuivie conformément aux dispositions des <strong>articles L.335-2 et suivants du Code de la propriété intellectuelle</strong>.</p>

<h3>4.2 — Marques</h3>
<p>Les dénominations sociales, marques, logos et signes distinctifs cités sur le Site sont la propriété de leurs détenteurs respectifs. Leur utilisation sans autorisation écrite préalable est interdite.</p>

<h3>4.3 — Logiciels open source</h3>
<p>Le Site utilise des composants open source (notamment PHPMailer, Chart.js) distribués sous leurs licences respectives (GNU LGPL, MIT).</p>

<h2>Article 5 — Liens hypertextes</h2>
<h3>5.1 — Liens sortants</h3>
<p>Le Site peut contenir des liens hypertextes vers d''autres sites internet ou ressources extérieures. L''AEIC n''exerce aucun contrôle sur ces sites et décline toute responsabilité quant à leur contenu, leur disponibilité ou leurs pratiques en matière de protection des données.</p>

<h3>5.2 — Liens entrants</h3>
<p>La création de liens hypertextes pointant vers le Site à partir d''un autre site nécessite l''<strong>autorisation écrite préalable</strong> de l''AEIC. Cette autorisation ne sera pas accordée si le site source contient des informations à caractère illicide, violent, polémique, pornographique, xénophobe ou pouvant porter atteinte à la sensibilité du plus grand nombre.</p>

<h2>Article 6 — Responsabilité</h2>
<h3>6.1 — Disponibilité</h3>
<p>L''AEIC s''efforce de permettre l''accès au Site 24 heures sur 24 et 7 jours sur 7, mais ne peut être tenue responsable des interruptions liées à des opérations de maintenance, de mise à jour, ou de problèmes techniques inhérents aux serveurs d''hébergement ou aux réseaux de communication.</p>

<h3>6.2 — Exactitude des informations</h3>
<p>L''AEIC s''efforce de fournir des informations exactes et à jour. Toutefois, elle ne saurait garantir l''exactitude, la précision ou l''exhaustivité des informations mises à disposition sur le Site. Les informations sont fournies « telles quelles » et peuvent être modifiées sans préavis.</p>

<h3>6.3 — Limitation de responsabilité</h3>
<p>L''AEIC ne saurait être tenue responsable :</p>
<ul>
<li>des erreurs, omissions ou indisponibilités du Site ;</li>
<li>de la présence de virus ou de tout autre élément nuisible sur le Site ;</li>
<li>des dommages directs ou indirects, matériels ou immatériels, résultant de l''utilisation du Site, de l''impossibilité d''y accéder, de la fiabilité des transmissions, ou des contenus diffusés ;</li>
<li>de l''utilisation faite par les internautes des informations diffusées.</li>
</ul>
<p>L''AEIC se réserve le droit de modifier, suspendre ou interrompre le Site, totalement ou partiellement, à tout moment, sans préavis.</p>

<h2>Article 7 — Protection des données personnelles</h2>
<p>Le traitement des données personnelles fait l''objet d''une <strong>politique de confidentialité</strong> dédiée, accessible à l''adresse : <a href="/privacy">/privacy</a>.</p>
<p>L''AEIC s''engage à respecter le <strong>Règlement Général sur la Protection des Données (RGPD — Règlement (UE) 2016/679)</strong> ainsi que la loi « Informatique et Libertés » du 6 janvier 1978 modifiée.</p>
<p>Conformément à l''article 34 de la loi « Informatique et Libertés », les utilisateurs disposent d''un droit d''accès, de modification, de rectification et de suppression des données les concernant. Ce droit peut être exercé :</p>
<ul>
<li>depuis l''espace membre (export et suppression des données) ;</li>
<li>par email à <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a>.</li>
</ul>

<h2>Article 8 — Cookies</h2>
<p>Le Site utilise un <strong>cookie de session strictement nécessaire</strong> à son fonctionnement (maintien de la connexion de l''utilisateur). Ce cookie est exempté de consentement au titre de l''article 82 de la loi « Informatique et Libertés ». Il est supprimé à la fermeture du navigateur ou à la déconnexion.</p>
<p>Le Site ne dépose <strong>aucun cookie publicitaire, de mesure d''audience ou de pistage tiers</strong>.</p>

<h2>Article 9 — Droit applicable et juridiction compétente</h2>
<p>Le présent Site et les présentes mentions légales sont régis par le <strong>droit français</strong>.</p>
<p>En cas de litige, et après l''échec de toute tentative de recherche d''une solution amiable, les tribunaux français seront seuls compétents pour connaître de ce litige.</p>
<p>Conformément à l''article L.612-1 du Code de la consommation, le consommateur peut recourir gratuitement à un médiateur de la consommation en vue de la résolution amiable d''un litige.</p>

<h2>Article 10 — Crédits</h2>
<p>Site développé par <strong>Remond Adrien</strong>, étudiant en informatique, dans le cadre d''un projet associatif bénévole.</p>
<p>Logo et identité visuelle : AEIC.</p>
<p>Charte graphique et design : Remond Adrien.</p>
<p>Hébergement : OVH SAS.</p>
<p>Envoi d''emails transactionnels : Brevo (Sendinblue).</p>
<p>Paiements en ligne : SumUp.</p>

<h2>Article 11 — Contact</h2>
<p>Pour toute question relative aux présentes mentions légales ou au fonctionnement du Site :</p>
<ul>
<li><strong>Email :</strong> <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a></li>
<li><strong>Adresse :</strong> AEIC — IUT de Calais, Département Informatique, 19 Rue Louis David, 62100 Calais</li>
</ul>

<p style="margin-top:2rem;font-size:0.85rem;color:#6b7280;">© 2026 AEIC — Association Étudiante Informatique de Calais. Tous droits réservés.</p>'
WHERE slug = 'legal';
