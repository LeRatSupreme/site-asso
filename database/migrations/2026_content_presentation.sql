-- ============================================================
--  AEIC — Contenu de la page « L'association » (/presentation)
--  Texte adapté du BDE INFO Lyon, pour le BUT INFO de Calais.
--  À appliquer : mysql -u aeic -p aeic < database/migrations/2026_content_presentation.sql
-- ============================================================

UPDATE pages SET title = 'L''association AEIC',
meta_title = 'L''association — AEIC',
meta_description = 'L''AEIC, association étudiante du département Informatique de l''IUT de Calais : vision, espaces, événements.',
content = '<p>L''<strong>AEIC</strong> (Association des Étudiants Informatique de Calais) est l''association étudiante du département Informatique de l''IUT de Calais. L''AEIC vise à faire se rencontrer les étudiants et à valoriser les interactions entre eux afin que leurs études se passent de la meilleure des façons — sans oublier qu''un bon associatif est avant tout un bon étudiant !</p>

<h2>Coordonnées</h2>
<ul>
<li><strong>IUT de Calais — département Informatique</strong>, 19 Rue Louis David, 62100 Calais.</li>
<li>Contact : <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a></li>
</ul>

<h2>Notre vision</h2>
<p>Plus qu''une simple association étudiante, l''AEIC est la communauté qui unit tous les élèves en informatique de l''IUT. Nous croyons que la réussite académique s''épanouit dans un environnement où règnent convivialité, entraide et esprit d''équipe. Notre philosophie est simple : transformer votre parcours universitaire en une aventure humaine enrichissante. Parce qu''un étudiant épanoui est un étudiant qui réussit, nous mettons tout en œuvre pour créer des liens durables entre les promotions et faciliter l''intégration de chacun.</p>

<h2>Des espaces pour tous</h2>
<p>L''AEIC dispose de deux espaces complémentaires :</p>
<ul>
<li><strong>En accès libre</strong>, un espace de pause et de détente avec tables et chaises, ainsi que <strong>2 micro-ondes</strong> pour réchauffer vos repas.</li>
<li><strong>Dans le local de l''association</strong>, un espace convivial pensé pour se retrouver et se défouler : des tables de travail pour réviser ou collaborer en groupe, une <strong>table de ping-pong</strong>, et un <strong>baby-foot</strong>.</li>
</ul>

<h2>Nos événements phares</h2>
<p>L''AEIC organise tout au long de l''année des événements qui rythment votre vie universitaire :</p>
<ul>
<li><strong>La Nuit de l''Info</strong> : rejoignez-nous pour cet événement national incontournable qui rassemble les étudiants en informatique de toute la France. Une nuit entière de programmation collaborative au service de causes humanitaires et solidaires.</li>
<li><strong>Afterworks</strong> : décompressez après les cours lors de nos rencontres conviviales — l''occasion parfaite de mieux se connaître en dehors des amphis.</li>
<li><strong>Barbecues</strong> : rien de tel qu''un barbecue pour rassembler toute la communauté autour d''un moment de partage et de convivialité en plein air.</li>
<li><strong>Soirées bowling</strong> : défiez vos camarades sur les pistes pour une soirée compétitive et pleine de fous rires.</li>
<li><strong>Sorties bar</strong> : prolongez les afterworks dans une ambiance festive et décontractée pour des soirées mémorables.</li>
</ul>
<p>Et ce n''est qu''un aperçu… Bien d''autres événements vous attendent tout au long de l''année. <strong>Rejoignez l''aventure !</strong></p>'
WHERE slug = 'presentation';
