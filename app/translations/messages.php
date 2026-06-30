<?php

/**
 * Catalogue de traductions AEIC — 7 langues.
 *
 * Structure : ['clé.point' => ['fr' => ..., 'en' => ..., 'de' => ..., 'es' => ..., 'zh' => ..., 'ja' => ..., 'pl' => ...]].
 *
 * Le français est la langue de référence (fallback). Les autres langues
 * peuvent utiliser des marqueurs {n}, {a}, {b}... remplacés dans les vues.
 *
 * Langues : fr, en, de, es, zh, ja, pl.
 */

return [

    /* ----------------------------------------------------------------- */
    /*  Navigation + actions                                             */
    /* ----------------------------------------------------------------- */
    'nav.home'              => ['fr' => 'Accueil', 'en' => 'Home', 'de' => 'Startseite', 'es' => 'Inicio', 'zh' => '首页', 'ja' => 'ホーム', 'pl' => 'Strona główna'],
    'nav.main.aria'         => ['fr' => 'Navigation principale', 'en' => 'Main navigation', 'de' => 'Hauptnavigation', 'es' => 'Navegación principal', 'zh' => '主导航', 'ja' => 'メインナビゲーション', 'pl' => 'Nawigacja główna'],
    'nav.events'            => ['fr' => 'Événements', 'en' => 'Events', 'de' => 'Veranstaltungen', 'es' => 'Eventos', 'zh' => '活动', 'ja' => 'イベント', 'pl' => 'Wydarzenia'],
    'nav.about'             => ['fr' => "L'association", 'en' => 'About', 'de' => 'Über uns', 'es' => 'Asociación', 'zh' => '协会', 'ja' => '协会について', 'pl' => 'O nas'],
    'nav.team'              => ['fr' => 'Équipe', 'en' => 'Team', 'de' => 'Team', 'es' => 'Equipo', 'zh' => '团队', 'ja' => 'チーム', 'pl' => 'Zespół'],
    'nav.polls'             => ['fr' => 'Sondages', 'en' => 'Polls', 'de' => 'Umfragen', 'es' => 'Encuestas', 'zh' => '投票', 'ja' => 'アンケート', 'pl' => 'Ankiety'],
    'nav.gallery'           => ['fr' => 'Galerie', 'en' => 'Gallery', 'de' => 'Galerie', 'es' => 'Galería', 'zh' => '图库', 'ja' => 'ギャラリー', 'pl' => 'Galeria'],
    'nav.login'             => ['fr' => 'Connexion', 'en' => 'Log in', 'de' => 'Anmelden', 'es' => 'Iniciar sesión', 'zh' => '登录', 'ja' => 'ログイン', 'pl' => 'Zaloguj'],
    'nav.register'          => ['fr' => "S'inscrire", 'en' => 'Register', 'de' => 'Registrieren', 'es' => 'Registrarse', 'zh' => '注册', 'ja' => '登録', 'pl' => 'Zarejestruj'],
    'nav.logout'            => ['fr' => 'Déconnexion', 'en' => 'Log out', 'de' => 'Abmelden', 'es' => 'Cerrar sesión', 'zh' => '退出', 'ja' => 'ログアウト', 'pl' => 'Wyloguj'],
    'nav.admin'             => ['fr' => 'Admin', 'en' => 'Admin', 'de' => 'Admin', 'es' => 'Admin', 'zh' => '管理', 'ja' => '管理', 'pl' => 'Admin'],
    'nav.account'           => ['fr' => 'Mon compte', 'en' => 'My account', 'de' => 'Mein Konto', 'es' => 'Mi cuenta', 'zh' => '我的账户', 'ja' => 'アカウント', 'pl' => 'Moje konto'],
    'nav.data'              => ['fr' => 'Mes données', 'en' => 'My data', 'de' => 'Meine Daten', 'es' => 'Mis datos', 'zh' => '我的数据', 'ja' => 'マイデータ', 'pl' => 'Moje dane'],
    'nav.lang.change'       => ['fr' => 'Changer de langue', 'en' => 'Change language', 'de' => 'Sprache ändern', 'es' => 'Cambiar idioma', 'zh' => '切换语言', 'ja' => '言語を変更', 'pl' => 'Zmień język'],
    'nav.theme'             => ['fr' => 'Thème', 'en' => 'Theme', 'de' => 'Design', 'es' => 'Tema', 'zh' => '主题', 'ja' => 'テーマ', 'pl' => 'Motyw'],
    'nav.theme.toggle'      => ['fr' => 'Basculer le thème clair/sombre', 'en' => 'Toggle light/dark theme', 'de' => 'Hell-/Dunkelmodus umschalten', 'es' => 'Alternar tema claro/oscuro', 'zh' => '切换明暗主题', 'ja' => 'テーマを切り替える', 'pl' => 'Przełącz motyw jasny/ciemny'],
    'nav.theme.light.dark'  => ['fr' => 'Thème clair / sombre', 'en' => 'Light / dark theme', 'de' => 'Hell-/Dunkelmodus', 'es' => 'Tema claro / oscuro', 'zh' => '明/暗主题', 'ja' => 'ライト/ダークテーマ', 'pl' => 'Motyw jasny/ciemny'],
    'nav.skip'              => ['fr' => 'Aller au contenu', 'en' => 'Skip to content', 'de' => 'Zum Inhalt', 'es' => 'Ir al contenido', 'zh' => '跳至内容', 'ja' => 'コンテンツへ移動', 'pl' => 'Przejdź do treści'],
    'nav.notifications'     => ['fr' => 'Notifications', 'en' => 'Notifications', 'de' => 'Benachrichtigungen', 'es' => 'Notificaciones', 'zh' => '通知', 'ja' => '通知', 'pl' => 'Powiadomienia'],
    'nav.notifications.empty' => ['fr' => 'Aucune notification.', 'en' => 'No notifications.', 'de' => 'Keine Benachrichtigungen.', 'es' => 'Sin notificaciones.', 'zh' => '暂无通知。', 'ja' => '通知はありません。', 'pl' => 'Brak powiadomień.'],
    'nav.notifications.markall' => ['fr' => 'Tout marquer comme lu', 'en' => 'Mark all as read', 'de' => 'Alle als gelesen markieren', 'es' => 'Marcar todo como leído', 'zh' => '全部标记为已读', 'ja' => 'すべて既読にする', 'pl' => 'Oznacz wszystkie jako przeczytane'],
    'nav.open_menu'         => ['fr' => 'Ouvrir le menu', 'en' => 'Open menu', 'de' => 'Menü öffnen', 'es' => 'Abrir menú', 'zh' => '打开菜单', 'ja' => 'メニューを開く', 'pl' => 'Otwórz menu'],

    /* ----------------------------------------------------------------- */
    /*  Footer                                                           */
    /* ----------------------------------------------------------------- */
    'footer.aria'           => ['fr' => 'Pied de page', 'en' => 'Footer', 'de' => 'Fußzeile', 'es' => 'Pie de página', 'zh' => '页脚', 'ja' => 'フッター', 'pl' => 'Stopka'],
    'footer.tag'            => ['fr' => '100 % étudiant.', 'en' => '100% student-run.', 'de' => '100 % von Studierenden.', 'es' => '100 % estudiantil.', 'zh' => '100% 学生运营。', 'ja' => '100% 学生運営。', 'pl' => '100 % studenckie.'],
    'footer.copy'           => ['fr' => 'Fait par les étudiants, pour les étudiants.', 'en' => 'Made by students, for students.', 'de' => 'Von Studierenden, für Studierende.', 'es' => 'Hecho por estudiantes, para estudiantes.', 'zh' => '由学生打造,为学生服务。', 'ja' => '学生による、学生のための運営。', 'pl' => 'Stworzone przez studentów, dla studentów.'],
    'footer.legal'          => ['fr' => 'Mentions légales', 'en' => 'Legal notice', 'de' => 'Impressum', 'es' => 'Aviso legal', 'zh' => '法律声明', 'ja' => '法的記述', 'pl' => 'Informacje prawne'],
    'footer.privacy'        => ['fr' => 'Confidentialité', 'en' => 'Privacy', 'de' => 'Datenschutz', 'es' => 'Privacidad', 'zh' => '隐私', 'ja' => 'プライバシー', 'pl' => 'Prywatność'],
    'footer.cgu'            => ['fr' => 'CGU', 'en' => 'Terms', 'de' => 'AGB', 'es' => 'Términos', 'zh' => '条款', 'ja' => '利用規約', 'pl' => 'Regulamin'],

    /* ----------------------------------------------------------------- */
    /*  Accueil                                                          */
    /* ----------------------------------------------------------------- */
    'home.eyebrow'          => ['fr' => 'Association étudiante · Informatique · Calais', 'en' => 'Student association · Computer Science · Calais', 'de' => 'Studierendenverein · Informatik · Calais', 'es' => 'Asociación estudiantil · Informática · Calais', 'zh' => '学生社团 · 计算机 · 加来', 'ja' => '学生団体 · 情報科学 · カレー', 'pl' => 'Stowarzyszenie studenckie · Informatyka · Calais'],
    'home.title.line1'      => ['fr' => "Plus qu'une asso.", 'en' => 'More than an association.', 'de' => 'Mehr als ein Verein.', 'es' => 'Más que una asociación.', 'zh' => '不止是一个社团。', 'ja' => '団体以上の存在。', 'pl' => 'Więcej niż stowarzyszenie.'],
    'home.title.line2'      => ['fr' => 'Ton campus, en mieux.', 'en' => 'Your campus, but better.', 'de' => 'Dein Campus, nur besser.', 'es' => 'Tu campus, pero mejor.', 'zh' => '你的校园,更出色。', 'ja' => 'あなたのキャンパス、もっと良く。', 'pl' => 'Twój campus, ale lepszy.'],
    'home.description'      => ['fr' => "L'AEIC réunit les étudiants en informatique du campus de Calais : événements, cafétéria, vie étudiante. Fait par les étudiants, pour les étudiants.", 'en' => 'The AEIC brings together computer science students on the Calais campus: events, cafeteria, student life. Made by students, for students.', 'de' => 'Die AEIC vereint Informatik-Studierende auf dem Campus Calais: Veranstaltungen, Cafeteria, studentisches Leben. Von Studierenden, für Studierende.', 'es' => 'La AEIC reúne a los estudiantes de informática del campus de Calais: eventos, cafetería, vida estudiantil. Hecho por estudiantes, para estudiantes.', 'zh' => 'AEIC 汇聚加来校区的计算机专业学生:活动、咖啡馆、学生生活。由学生打造,为学生服务。', 'ja' => 'AEICはカレーキャンパスの情報科学の学生をまとめます:イベント、カフェテリア、学生生活。学生による、学生のための活動。', 'pl' => 'AEIC łączy studentów informatyki na kampusie w Calais: wydarzenia, kafeteria, życie studenckie. Tworzone przez studentów, dla studentów.'],
    'home.cta.join'         => ['fr' => "Rejoindre l'AEIC", 'en' => 'Join AEIC', 'de' => 'AEIC beitreten', 'es' => 'Únete a la AEIC', 'zh' => '加入 AEIC', 'ja' => 'AEICに参加', 'pl' => 'Dołącz do AEIC'],
    'home.cta.events'       => ['fr' => 'Voir les événements', 'en' => 'See events', 'de' => 'Veranstaltungen ansehen', 'es' => 'Ver eventos', 'zh' => '查看活动', 'ja' => 'イベントを見る', 'pl' => 'Zobacz wydarzenia'],
    'home.features.eyebrow' => ['fr' => "Pourquoi l'AEIC", 'en' => 'Why AEIC', 'de' => 'Warum AEIC', 'es' => 'Por qué la AEIC', 'zh' => '为什么选择 AEIC', 'ja' => 'AEICの魅力', 'pl' => 'Dlaczego AEIC'],
    'home.features.title'   => ['fr' => 'La vie étudiante, sans friction', 'en' => 'Student life, friction-free', 'de' => 'Studierendenleben ohne Reibung', 'es' => 'Vida estudiantil sin fricciones', 'zh' => '无障碍学生生活', 'ja' => '摩擦のない学生生活', 'pl' => 'Życie studenckie bez komplikacji'],
    'home.feature.events.title' => ['fr' => 'Événements', 'en' => 'Events', 'de' => 'Veranstaltungen', 'es' => 'Eventos', 'zh' => '活动', 'ja' => 'イベント', 'pl' => 'Wydarzenia'],
    'home.feature.events.desc' => ['fr' => "Soirées d'intégration, LAN, conférences : un agenda pensé pour les étudiants en info.", 'en' => 'Integration parties, LANs, conferences: an agenda designed for CS students.', 'de' => 'Begrüßungsfeiern, LANs, Konferenzen: ein Terminkalender für Informatik-Studierende.', 'es' => 'Fiestas de integración, LAN, conferencias: una agenda pensada para estudiantes de informática.', 'zh' => '迎新晚会、LAN派对、讲座:专为计算机学生设计的日程。', 'ja' => '新歓、LAN、講演会:情報学生のために作られたスケジュール。', 'pl' => 'Wieczory integracyjne, LAN, konferencje: kalendarz stworzony dla studentów informatyki.'],
    'home.feature.cafeteria.title' => ['fr' => 'Cafétéria', 'en' => 'Cafeteria', 'de' => 'Cafeteria', 'es' => 'Cafetería', 'zh' => '咖啡馆', 'ja' => 'カフェテリア', 'pl' => 'Kafeteria'],
    'home.feature.cafeteria.desc' => ['fr' => 'Des prix étudiant, commandable en ligne, prêt à récupérer entre deux cours.', 'en' => 'Student prices, online ordering, ready to pick up between classes.', 'de' => 'Studierendenpreise, online bestellbar, abholbereit zwischen den Vorlesungen.', 'es' => 'Precios de estudiante, pedidos en línea, listo para recoger entre clases.', 'zh' => '学生价格、在线点餐、课间即可取餐。', 'ja' => '学生価格、オンライン注文、授業の合間に受け取り。', 'pl' => 'Studenckie ceny, zamówienia online, gotowe do odbioru między zajęciami.'],
    'home.feature.community.title' => ['fr' => 'Communauté', 'en' => 'Community', 'de' => 'Community', 'es' => 'Comunidad', 'zh' => '社区', 'ja' => 'コミュニティ', 'pl' => 'Społeczność'],
    'home.feature.community.desc' => ['fr' => "Un réseau d'entraide, des projets, et des gens qui font avancer le campus.", 'en' => 'A support network, projects, and people who move the campus forward.', 'de' => 'Ein Netzwerk für gegenseitige Hilfe, Projekte und Menschen, die den Campus voranbringen.', 'es' => 'Una red de apoyo, proyectos y personas que hacen avanzar el campus.', 'zh' => '互助网络、各类项目,以及推动校园前进的人。', 'ja' => '助け合いのネットワーク、プロジェクト、キャンパスを動かす人々。', 'pl' => 'Sieć wzajemnej pomocy, projekty i ludzie, którzy napędzają kampus.'],
    'home.stat.student'     => ['fr' => 'Étudiant', 'en' => 'Student', 'de' => 'Studierender', 'es' => 'Estudiante', 'zh' => '学生', 'ja' => '学生', 'pl' => 'Student'],
    'home.stat.members'     => ['fr' => 'Membres', 'en' => 'Members', 'de' => 'Mitglieder', 'es' => 'Miembros', 'zh' => '成员', 'ja' => 'メンバー', 'pl' => 'Członkowie'],
    'home.stat.events'      => ['fr' => 'Événements', 'en' => 'Events', 'de' => 'Veranstaltungen', 'es' => 'Eventos', 'zh' => '活动', 'ja' => 'イベント', 'pl' => 'Wydarzenia'],
    'home.stat.easy'        => ['fr' => 'Prise de tête', 'en' => 'Hassle', 'de' => 'Stress', 'es' => 'Complicaciones', 'zh' => '烦恼', 'ja' => '手間', 'pl' => 'Stres'],
    'home.stats.aria'       => ['fr' => "L'AEIC en chiffres", 'en' => 'The AEIC in numbers', 'de' => 'Die AEIC in Zahlen', 'es' => 'La AEIC en cifras', 'zh' => 'AEIC 数据', 'ja' => 'AEICを数字で', 'pl' => 'AEIC w liczbach'],
    'home.upcoming.eyebrow' => ['fr' => 'Agenda', 'en' => 'Agenda', 'de' => 'Termine', 'es' => 'Agenda', 'zh' => '日程', 'ja' => '予定', 'pl' => 'Agenda'],
    'home.upcoming.title'   => ['fr' => 'Prochains événements', 'en' => 'Upcoming events', 'de' => 'Anstehende Veranstaltungen', 'es' => 'Próximos eventos', 'zh' => '即将到来的活动', 'ja' => '今後のイベント', 'pl' => 'Nadchodzące wydarzenia'],
    'home.upcoming.empty'   => ['fr' => 'Aucun événement annoncé pour le moment. Revenez bientôt !', 'en' => 'No events announced yet. Check back soon!', 'de' => 'Noch keine Veranstaltungen angekündigt. Schau bald wieder vorbei!', 'es' => 'Ningún evento anunciado por ahora. ¡Vuelve pronto!', 'zh' => '暂无公布的活动。敬请期待!', 'ja' => 'まだ告知されたイベントはありません。また来てください!', 'pl' => 'Brak ogłoszonych wydarzeń. Wracaj wkrótce!'],
    'home.upcoming.more'    => ['fr' => 'Tout voir →', 'en' => 'See all →', 'de' => 'Alle ansehen →', 'es' => 'Ver todo →', 'zh' => '查看全部 →', 'ja' => 'すべて見る →', 'pl' => 'Zobacz wszystko →'],

    /* ----------------------------------------------------------------- */
    /*  Événements (liste)                                               */
    /* ----------------------------------------------------------------- */
    'events.eyebrow'        => ['fr' => 'Agenda AEIC', 'en' => 'AEIC agenda', 'de' => 'AEIC-Terminkalender', 'es' => 'Agenda AEIC', 'zh' => 'AEIC 日程', 'ja' => 'AEICアジェンダ', 'pl' => 'Agenda AEIC'],
    'events.title'          => ['fr' => 'Les prochains rendez-vous.', 'en' => 'The next meetups.', 'de' => 'Die nächsten Termine.', 'es' => 'Los próximos encuentros.', 'zh' => '接下来的活动。', 'ja' => '今後の予定。', 'pl' => 'Nadchodzące spotkania.'],
    'events.lead'           => ['fr' => '{a} à venir · {b} passés', 'en' => '{a} upcoming · {b} past', 'de' => '{a} anstehend · {b} vorbei', 'es' => '{a} próximos · {b} pasados', 'zh' => '{a} 即将 · {b} 已结束', 'ja' => '{a} 予定 · {b} 終了', 'pl' => '{a} nadchodzących · {b} minionych'],
    'events.empty'          => ['fr' => 'Aucun événement à venir pour le moment. Revenez vite !', 'en' => 'No upcoming events yet. Check back soon!', 'de' => 'Aktuell keine anstehenden Veranstaltungen. Schau bald wieder vorbei!', 'es' => 'Ningún evento próximo por ahora. ¡Vuelve pronto!', 'zh' => '暂无即将到来的活动。敬请期待!', 'ja' => '今後のイベントはまだありません。また来てください!', 'pl' => 'Brak nadchodzących wydarzeń. Wracaj wkrótce!'],
    'events.archives'       => ['fr' => 'Archives', 'en' => 'Archives', 'de' => 'Archiv', 'es' => 'Archivos', 'zh' => '归档', 'ja' => 'アーカイブ', 'pl' => 'Archiwum'],
    'events.others'         => ['fr' => 'Autres', 'en' => 'Other', 'de' => 'Sonstige', 'es' => 'Otros', 'zh' => '其他', 'ja' => 'その他', 'pl' => 'Inne'],

    /* ----------------------------------------------------------------- */
    /*  Événement (détail)                                               */
    /* ----------------------------------------------------------------- */
    'event.back'            => ['fr' => '← Retour aux événements', 'en' => '← Back to events', 'de' => '← Zurück zu Veranstaltungen', 'es' => '← Volver a eventos', 'zh' => '← 返回活动', 'ja' => '← イベントに戻る', 'pl' => '← Wróć do wydarzeń'],
    'event.eyebrow'         => ['fr' => 'Événement', 'en' => 'Event', 'de' => 'Veranstaltung', 'es' => 'Evento', 'zh' => '活动', 'ja' => 'イベント', 'pl' => 'Wydarzenie'],
    'event.ended'           => ['fr' => 'Événement terminé', 'en' => 'Event ended', 'de' => 'Veranstaltung beendet', 'es' => 'Evento finalizado', 'zh' => '活动已结束', 'ja' => 'イベント終了', 'pl' => 'Wydarzenie zakończone'],
    'event.details'         => ['fr' => 'Détails', 'en' => 'Details', 'de' => 'Details', 'es' => 'Detalles', 'zh' => '详情', 'ja' => '詳細', 'pl' => 'Szczegóły'],
    'event.gallery'         => ['fr' => 'Galerie', 'en' => 'Gallery', 'de' => 'Galerie', 'es' => 'Galería', 'zh' => '图库', 'ja' => 'ギャラリー', 'pl' => 'Galeria'],
    'event.photos'          => ['fr' => 'Photos', 'en' => 'Photos', 'de' => 'Fotos', 'es' => 'Fotos', 'zh' => '照片', 'ja' => '写真', 'pl' => 'Zdjęcia'],
    'event.add_calendar'    => ['fr' => '📅 Ajouter au calendrier', 'en' => '📅 Add to calendar', 'de' => '📅 Zum Kalender hinzufügen', 'es' => '📅 Añadir al calendario', 'zh' => '📅 添加到日历', 'ja' => '📅 カレンダーに追加', 'pl' => '📅 Dodaj do kalendarza'],
    'event.location'        => ['fr' => 'Localisation', 'en' => 'Location', 'de' => 'Standort', 'es' => 'Ubicación', 'zh' => '位置', 'ja' => '場所', 'pl' => 'Lokalizacja'],
    'event.where'           => ['fr' => "Où se trouve l'événement", 'en' => 'Where the event takes place', 'de' => 'Wo die Veranstaltung stattfindet', 'es' => 'Dónde se realiza el evento', 'zh' => '活动地点', 'ja' => 'イベントの場所', 'pl' => 'Gdzie odbywa się wydarzenie'],
    'event.itinerary'       => ['fr' => '📍 Itinéraire (Google Maps)', 'en' => '📍 Directions (Google Maps)', 'de' => '📍 Route (Google Maps)', 'es' => '📍 Cómo llegar (Google Maps)', 'zh' => '📍 路线 (Google 地图)', 'ja' => '📍 道順 (Google マップ)', 'pl' => '📍 Trasa (Google Maps)'],
    'event.participate'     => ['fr' => 'Participer', 'en' => 'Take part', 'de' => 'Teilnehmen', 'es' => 'Participar', 'zh' => '参与', 'ja' => '参加', 'pl' => 'Weź udział'],
    'event.price'           => ['fr' => 'Prix', 'en' => 'Price', 'de' => 'Preis', 'es' => 'Precio', 'zh' => '价格', 'ja' => '料金', 'pl' => 'Cena'],
    'event.free'            => ['fr' => 'Gratuit', 'en' => 'Free', 'de' => 'Kostenlos', 'es' => 'Gratis', 'zh' => '免费', 'ja' => '無料', 'pl' => 'Bezpłatne'],
    'event.places_left'     => ['fr' => 'Plus que {n} place(s)', 'en' => 'Only {n} spot(s) left', 'de' => 'Noch {n} Platz/Plätze', 'es' => '{n} plaza(s) libre(s)', 'zh' => '仅剩 {n} 个名额', 'ja' => '残り {n} 席', 'pl' => 'Zostało {n} miejsc'],
    'event.signed_up'       => ['fr' => '{a}/{b} inscrit·e·s', 'en' => '{a}/{b} registered', 'de' => '{a}/{b} angemeldet', 'es' => '{a}/{b} inscritos', 'zh' => '{a}/{b} 已报名', 'ja' => '{a}/{b} 登録済み', 'pl' => '{a}/{b} zapisanych'],
    'event.full'            => ['fr' => 'Complet', 'en' => 'Full', 'de' => 'Voll', 'es' => 'Completo', 'zh' => '已满', 'ja' => '満員', 'pl' => 'Brak miejsc'],
    'event.registered'      => ['fr' => 'Vous êtes inscrit·e', 'en' => 'You are registered', 'de' => 'Du bist angemeldet', 'es' => 'Estás inscrito', 'zh' => '您已报名', 'ja' => '登録済みです', 'pl' => 'Jesteś zapisany'],
    'event.unregister'      => ['fr' => 'Se désinscrire', 'en' => 'Unregister', 'de' => 'Abmelden', 'es' => 'Cancelar inscripción', 'zh' => '取消报名', 'ja' => '登録を取り消す', 'pl' => 'Wypisz się'],
    'event.waitlist'        => ['fr' => "Sur liste d'attente — position {n}", 'en' => 'On the waitlist — position {n}', 'de' => 'Auf der Warteliste — Position {n}', 'es' => 'En lista de espera — posición {n}', 'zh' => '候补名单 — 第 {n} 位', 'ja' => 'キャンセル待ち — {n} 番目', 'pl' => 'Na liście oczekujących — pozycja {n}'],
    'event.leave_queue'     => ['fr' => 'Quitter la file', 'en' => 'Leave the queue', 'de' => 'Warteschlange verlassen', 'es' => 'Salir de la cola', 'zh' => '退出排队', 'ja' => '待機列を離れる', 'pl' => 'Opuść kolejkę'],
    'event.choose'          => ['fr' => '— Choisir —', 'en' => '— Choose —', 'de' => '— Auswählen —', 'es' => '— Elegir —', 'zh' => '— 选择 —', 'ja' => '— 選択 —', 'pl' => '— Wybierz —'],
    'event.required'        => ['fr' => 'Obligatoire', 'en' => 'Required', 'de' => 'Erforderlich', 'es' => 'Obligatorio', 'zh' => '必选', 'ja' => '必須', 'pl' => 'Wymagane'],
    'event.register_btn'    => ["fr" => "Je m'inscris", 'en' => 'Register', 'de' => 'Anmelden', 'es' => 'Inscribirme', 'zh' => '报名', 'ja' => '登録する', 'pl' => 'Zapisz się'],
    'event.join_waitlist'   => ['fr' => "Liste d'attente", 'en' => 'Join waitlist', 'de' => 'Warteliste', 'es' => 'Lista de espera', 'zh' => '加入候补', 'ja' => 'キャンセル待ち', 'pl' => 'Lista oczekujących'],
    'event.login_to_register' => ['fr' => 'Connectez-vous pour vous inscrire à cet événement.', 'en' => 'Log in to register for this event.', 'de' => 'Melde dich an, um dich für diese Veranstaltung anzumelden.', 'es' => 'Inicia sesión para inscribirte en este evento.', 'zh' => '登录后即可报名此活动。', 'ja' => 'このイベントに登録するにはログインしてください。', 'pl' => 'Zaloguj się, aby zapisać się na to wydarzenie.'],
    'event.login_btn'       => ['fr' => 'Se connecter', 'en' => 'Log in', 'de' => 'Anmelden', 'es' => 'Iniciar sesión', 'zh' => '登录', 'ja' => 'ログイン', 'pl' => 'Zaloguj'],
    'event.create_account'  => ['fr' => 'Créer un compte', 'en' => 'Create account', 'de' => 'Konto erstellen', 'es' => 'Crear cuenta', 'zh' => '创建账户', 'ja' => 'アカウントを作成', 'pl' => 'Utwórz konto'],
    'event.participants'    => ['fr' => 'Participants', 'en' => 'Participants', 'de' => 'Teilnehmer', 'es' => 'Participantes', 'zh' => '参与者', 'ja' => '参加者', 'pl' => 'Uczestnicy'],
    'event.no_participants' => ['fr' => 'Soyez les premiers à vous inscrire !', 'en' => 'Be the first to register!', 'de' => 'Sei der/die Erste!', 'es' => '¡Sé el primero en inscribirte!', 'zh' => '成为第一个报名的人!', 'ja' => '最初の登録者になろう!', 'pl' => 'Bądź pierwszą osobą, która się zapisze!'],
    'event.qr.checkin'      => ['fr' => 'QR code de check-in', 'en' => 'Check-in QR code', 'de' => 'Check-in-QR-Code', 'es' => 'Código QR de check-in', 'zh' => '签到二维码', 'ja' => 'チェックインQRコード', 'pl' => 'Kod QR do odprawy'],
    'event.qr.help'         => ['fr' => "Présentez ce QR code à l'entrée de l'événement.", 'en' => 'Show this QR code at the event entrance.', 'de' => 'Zeige diesen QR-Code am Eingang der Veranstaltung.', 'es' => 'Muestra este código QR en la entrada del evento.', 'zh' => '请在活动入口处出示此二维码。', 'ja' => 'イベントの入り口でこのQRコードを提示してください。', 'pl' => 'Pokaż ten kod QR przy wejściu na wydarzenie.'],
    'event.map.aria'        => ['fr' => 'Carte — {title}', 'en' => 'Map — {title}', 'de' => 'Karte — {title}', 'es' => 'Mapa — {title}', 'zh' => '地图 — {title}', 'ja' => '地図 — {title}', 'pl' => 'Mapa — {title}'],

    /* ----------------------------------------------------------------- */
    /*  Présentation                                                     */
    /* ----------------------------------------------------------------- */
    'about.eyebrow'         => ['fr' => 'Qui sommes-nous ?', 'en' => 'Who are we?', 'de' => 'Wer wir sind', 'es' => 'Quiénes somos', 'zh' => '我们是谁?', 'ja' => '私たちについて', 'pl' => 'Kim jesteśmy?'],
    'about.title'           => ['fr' => "L'AEIC, c'est le campus qui prend vie.", 'en' => 'The AEIC brings the campus to life.', 'de' => 'Die AEIC belebt den Campus.', 'es' => 'La AEIC da vida al campus.', 'zh' => 'AEIC,让校园充满活力。', 'ja' => 'AEICは、命を吹き込まれたキャンパスです。', 'pl' => 'AEIC ożywia kampus.'],
    'about.lead'            => ['fr' => 'Association Étudiante Informatique de Calais — fait par les étudiants, pour les étudiants.', 'en' => 'Student Computer Association of Calais — made by students, for students.', 'de' => 'Studierendenverein Informatik Calais — von Studierenden, für Studierende.', 'es' => 'Asociación Estudiantil de Informática de Calais — hecho por estudiantes, para estudiantes.', 'zh' => '加来计算机学生协会 —— 由学生打造,为学生服务。', 'ja' => 'カレー情報科学学生協会 — 学生による、学生のための運営。', 'pl' => 'Stowarzyszenie Studentów Informatyki w Calais — tworzone przez studentów, dla studentów.'],
    'about.mission'         => ['fr' => 'Notre mission', 'en' => 'Our mission', 'de' => 'Unsere Mission', 'es' => 'Nuestra misión', 'zh' => '我们的使命', 'ja' => '私たちのミッション', 'pl' => 'Nasza misja'],
    'about.mission.desc'    => ['fr' => "Créer du lien entre les étudiants en informatique de Calais, rythmer la vie du campus et rendre concret ce qui ne l'était pas : événements, vie associative, entraide.", 'en' => 'Building connections among CS students in Calais, bringing campus life to a rhythm, and making the abstract concrete: events, student life, mutual aid.', 'de' => 'Verbindungen zwischen den Informatik-Studierenden in Calais schaffen, das Campusleben prägen und das Abstrakte konkret machen: Veranstaltungen, Vereinsleben, gegenseitige Hilfe.', 'es' => 'Crear vínculos entre los estudiantes de informática de Calais, dar ritmo a la vida del campus y hacer concreto lo que no lo era: eventos, vida asociativa, ayuda mutua.', 'zh' => '在加来的计算机学生之间建立联系,为校园生活注入节奏,让抽象变为具体:活动、社团生活、互助。', 'ja' => 'カレーの情報学生の間につながりを作り、キャンパス生活にリズムを与え、抽象的なものを具体化する:イベント、学生団体の活動、助け合い。', 'pl' => 'Tworzyć więzi między studentami informatyki w Calais, nadawać rytm życiu kampusu i czynić konkretne to, co wcześniej nie było: wydarzenia, życie studenckie, wzajemna pomoc.'],
    'about.values.eyebrow'  => ['fr' => 'Nos valeurs', 'en' => 'Our values', 'de' => 'Unsere Werte', 'es' => 'Nuestros valores', 'zh' => '我们的价值观', 'ja' => '私たちの価値観', 'pl' => 'Nasze wartości'],
    'about.values.title'    => ['fr' => 'Ce qui nous fait avancer', 'en' => 'What drives us forward', 'de' => 'Was uns voranbringt', 'es' => 'Lo que nos impulsa', 'zh' => '推动我们前行的力量', 'ja' => '私たちを前へ進めるもの', 'pl' => 'Co nami rusza'],
    'about.value.proximity' => ['fr' => 'Proximité', 'en' => 'Proximity', 'de' => 'Nähe', 'es' => 'Proximidad', 'zh' => '贴近学生', 'ja' => '近さ', 'pl' => 'Bliskość'],
    'about.value.proximity.desc' => ['fr' => 'Des étudiants comme vous, à côté, qui écoutent et agissent.', 'en' => 'Students like you, right next to you, who listen and act.', 'de' => 'Studierende wie du, direkt daneben, die zuhören und handeln.', 'es' => 'Estudiantes como tú, a tu lado, que escuchan y actúan.', 'zh' => '和你一样的学生,就在身边,倾听并行动。', 'ja' => 'あなたと同じ学生が、すぐそばで耳を傾け、行動します。', 'pl' => 'Studenci tacy jak Ty, tuż obok, którzy słuchają i działają.'],
    'about.value.passion'   => ['fr' => 'Passion', 'en' => 'Passion', 'de' => 'Leidenschaft', 'es' => 'Pasión', 'zh' => '热情', 'ja' => '情熱', 'pl' => 'Pasja'],
    'about.value.passion.desc' => ['fr' => "L'informatique et la vie de campus : nos deux moteurs.", 'en' => 'Computer science and campus life: our two driving forces.', 'de' => 'Informatik und Campusleben: unsere zwei Antriebe.', 'es' => 'La informática y la vida del campus: nuestros dos motores.', 'zh' => '计算机与校园生活:我们的两大动力。', 'ja' => '情報科学とキャンパス生活:私たちの二つの原動力。', 'pl' => 'Informatyka i życie kampusu: nasze dwa silniki.'],
    'about.value.sharing'   => ['fr' => 'Partage', 'en' => 'Sharing', 'de' => 'Teilen', 'es' => 'Compartir', 'zh' => '分享', 'ja' => '共有', 'pl' => 'Dzielenie się'],
    'about.value.sharing.desc' => ['fr' => 'Transmettre, entraider, ouvrir des opportunités à tous.', 'en' => 'Passing on knowledge, helping each other, opening opportunities for everyone.', 'de' => 'Wissen weitergeben, einander helfen, allen Chancen eröffnen.', 'es' => 'Transmitir, ayudar, abrir oportunidades a todos.', 'zh' => '传授知识、互助,为每个人打开机会。', 'ja' => '知識を伝え、助け合い、すべての人に機会を開く。', 'pl' => 'Przekazywać wiedzę, pomagać, otwierać możliwości dla wszystkich.'],
    'about.stats.title'     => ['fr' => 'Des actions concrètes', 'en' => 'Concrete actions', 'de' => 'Konkrete Taten', 'es' => 'Acciones concretas', 'zh' => '切实的行动', 'ja' => '具体的な行動', 'pl' => 'Konkretne działania'],
    'about.cta.title'       => ['fr' => 'Envie de nous rejoindre ?', 'en' => 'Want to join us?', 'de' => 'Möchtest du dabei sein?', 'es' => '¿Quieres unirte a nosotros?', 'zh' => '想加入我们吗?', 'ja' => '私たちと一緒に活動しませんか?', 'pl' => 'Chcesz do nas dołączyć?'],

    /* ----------------------------------------------------------------- */
    /*  Équipe                                                           */
    /* ----------------------------------------------------------------- */
    'team.eyebrow'          => ['fr' => 'Le bureau', 'en' => 'The board', 'de' => 'Der Vorstand', 'es' => 'La junta', 'zh' => '核心团队', 'ja' => '役員', 'pl' => 'Zarząd'],
    'team.title'            => ['fr' => "Ceux qui font vivre l'AEIC.", 'en' => 'The people behind the AEIC.', 'de' => 'Die, die die AEIC am Leben erhalten.', 'es' => 'Quienes mantienen viva la AEIC.', 'zh' => '让 AEIC 充满活力的人。', 'ja' => 'AEICを支える人々。', 'pl' => 'Osoby, które ożywiają AEIC.'],
    'team.lead'             => ['fr' => 'Les étudiants du bureau, pôle par pôle.', 'en' => 'The board students, area by area.', 'de' => 'Die Studierenden des Vorstands, Bereich für Bereich.', 'es' => 'Los estudiantes de la junta, área por área.', 'zh' => '核心团队的学生,按部门介绍。', 'ja' => '役員の学生を、部門ごとに。', 'pl' => 'Studenci zarządu, pion po pionie.'],
    'team.empty'            => ['fr' => 'Contenu à venir. Le bureau sera bientôt présenté ici.', 'en' => 'Content coming soon. The board will be presented here shortly.', 'de' => 'Inhalt folgt. Der Vorstand wird hier bald vorgestellt.', 'es' => 'Contenido próximamente. La junta se presentará aquí pronto.', 'zh' => '内容即将上线,核心团队将很快在此展示。', 'ja' => 'コンテンツ準備中。役員はまもなくここで紹介されます。', 'pl' => 'Treść wkrótce. Zarząd zostanie tu wkrótce zaprezentowany.'],
    'team.board.eyebrow'    => ['fr' => 'Bureau restreint', 'en' => 'Core board', 'de' => 'Kernvorstand', 'es' => 'Junta principal', 'zh' => '核心管理层', 'ja' => '役員コア', 'pl' => 'Zarząd główny'],
    'team.board.title'      => ['fr' => 'Le bureau', 'en' => 'The board', 'de' => 'Der Vorstand', 'es' => 'La junta', 'zh' => '管理层', 'ja' => '役員', 'pl' => 'Zarząd'],
    'team.all.eyebrow'      => ['fr' => "Toute l'équipe", 'en' => 'The whole team', 'de' => 'Das gesamte Team', 'es' => 'Todo el equipo', 'zh' => '全部成员', 'ja' => 'チーム全体', 'pl' => 'Cały zespół'],
    'team.all.title'        => ['fr' => 'Les membres', 'en' => 'The members', 'de' => 'Die Mitglieder', 'es' => 'Los miembros', 'zh' => '成员', 'ja' => 'メンバー', 'pl' => 'Członkowie'],

    /* ----------------------------------------------------------------- */
    /*  Sondages (liste + détail)                                        */
    /* ----------------------------------------------------------------- */
    'polls.eyebrow'         => ['fr' => 'Sondages AEIC', 'en' => 'AEIC polls', 'de' => 'AEIC-Umfragen', 'es' => 'Encuestas AEIC', 'zh' => 'AEIC 投票', 'ja' => 'AEICアンケート', 'pl' => 'Ankiety AEIC'],
    'polls.title'           => ['fr' => 'Votre avis compte.', 'en' => 'Your opinion matters.', 'de' => 'Deine Meinung zählt.', 'es' => 'Tu opinión cuenta.', 'zh' => '你的意见很重要。', 'ja' => 'あなたの声は大事です。', 'pl' => 'Twoja opinia się liczy.'],
    'polls.lead'            => ['fr' => '{n} sondage(s) · résultats en temps réel', 'en' => '{n} poll(s) · real-time results', 'de' => '{n} Umfrage(n) · Ergebnisse in Echtzeit', 'es' => '{n} encuesta(s) · resultados en tiempo real', 'zh' => '{n} 个投票 · 实时结果', 'ja' => '{n} 件のアンケート · リアルタイム結果', 'pl' => '{n} ankieta · wyniki na żywo'],
    'polls.empty'           => ['fr' => 'Aucun sondage ouvert pour le moment. Revenez vite !', 'en' => 'No open polls yet. Check back soon!', 'de' => 'Aktuell keine offenen Umfragen. Schau bald wieder vorbei!', 'es' => 'Ninguna encuesta abierta por ahora. ¡Vuelve pronto!', 'zh' => '暂无开放的投票。敬请期待!', 'ja' => '現在開いているアンケートはありません。また来てください!', 'pl' => 'Brak otwartych ankiet. Wracaj wkrótce!'],
    'poll.back'             => ['fr' => '← Retour aux sondages', 'en' => '← Back to polls', 'de' => '← Zurück zu den Umfragen', 'es' => '← Volver a las encuestas', 'zh' => '← 返回投票', 'ja' => '← アンケートに戻る', 'pl' => '← Wróć do ankiet'],
    'poll.eyebrow.lg'       => ['fr' => '📊 Sondage', 'en' => '📊 Poll', 'de' => '📊 Umfrage', 'es' => '📊 Encuesta', 'zh' => '📊 投票', 'ja' => '📊 アンケート', 'pl' => '📊 Ankieta'],
    'poll.closed'           => ['fr' => '🔒 Fermé', 'en' => '🔒 Closed', 'de' => '🔒 Geschlossen', 'es' => '🔒 Cerrada', 'zh' => '🔒 已关闭', 'ja' => '🔒 終了', 'pl' => '🔒 Zamknięta'],
    'poll.open'             => ['fr' => '🟢 Ouvert', 'en' => '🟢 Open', 'de' => '🟢 Offen', 'es' => '🟢 Abierta', 'zh' => '🟢 开放中', 'ja' => '🟢 受付中', 'pl' => '🟢 Otwarta'],
    'poll.in_progress'      => ['fr' => '📊 En cours', 'en' => '📊 In progress', 'de' => '📊 Läuft', 'es' => '📊 En curso', 'zh' => '📊 进行中', 'ja' => '📊 進行中', 'pl' => '📊 W toku'],
    'poll.multiple'         => ['fr' => '☑️ Choix multiple', 'en' => '☑️ Multiple choice', 'de' => '☑️ Mehrfachauswahl', 'es' => '☑️ Elección múltiple', 'zh' => '☑️ 多选', 'ja' => '☑️ 複数選択', 'pl' => '☑️ Wielokrotnego wyboru'],
    'poll.single'           => ['fr' => '🔘 Choix unique', 'en' => '🔘 Single choice', 'de' => '🔘 Einfachauswahl', 'es' => '🔘 Elección única', 'zh' => '🔘 单选', 'ja' => '🔘 単一選択', 'pl' => '🔘 Pojedynczego wyboru'],
    'poll.voters'           => ['fr' => '👥 {n} votant(s)', 'en' => '👥 {n} voter(s)', 'de' => '👥 {n} Stimmberechtigte', 'es' => '👥 {n} votante(s)', 'zh' => '👥 {n} 位投票者', 'ja' => '👥 {n} 人の投票者', 'pl' => '👥 {n} głosujących'],
    'poll.vote.eyebrow'     => ['fr' => 'Votre avis compte', 'en' => 'Your opinion matters', 'de' => 'Deine Meinung zählt', 'es' => 'Tu opinión cuenta', 'zh' => '你的意见很重要', 'ja' => 'あなたの声は大事です', 'pl' => 'Twoja opinia się liczy'],
    'poll.vote.now'         => ['fr' => 'Votez maintenant', 'en' => 'Vote now', 'de' => 'Jetzt abstimmen', 'es' => 'Vota ahora', 'zh' => '立即投票', 'ja' => '今すぐ投票', 'pl' => 'Głosuj teraz'],
    'poll.vote.multiple.desc' => ['fr' => 'Vous pouvez choisir plusieurs réponses.', 'en' => 'You can choose several answers.', 'de' => 'Du kannst mehrere Antworten wählen.', 'es' => 'Puedes elegir varias respuestas.', 'zh' => '你可以选择多个选项。', 'ja' => '複数の回答を選択できます。', 'pl' => 'Możesz wybrać kilka odpowiedzi.'],
    'poll.vote.single.desc' => ['fr' => 'Choisissez une seule réponse.', 'en' => 'Choose a single answer.', 'de' => 'Wähle nur eine Antwort.', 'es' => 'Elige una sola respuesta.', 'zh' => '请选择一个选项。', 'ja' => '回答を一つ選んでください。', 'pl' => 'Wybierz jedną odpowiedź.'],
    'poll.vote.submit'      => ['fr' => '🗳️ Voter', 'en' => '🗳️ Vote', 'de' => '🗳️ Abstimmen', 'es' => '🗳️ Votar', 'zh' => '🗳️ 投票', 'ja' => '🗳️ 投票する', 'pl' => '🗳️ Głosuj'],
    'poll.results'          => ['fr' => 'Résultats', 'en' => 'Results', 'de' => 'Ergebnisse', 'es' => 'Resultados', 'zh' => '结果', 'ja' => '結果', 'pl' => 'Wyniki'],
    'poll.results.live'     => ['fr' => '📊 Résultats en direct', 'en' => '📊 Live results', 'de' => '📊 Live-Ergebnisse', 'es' => '📊 Resultados en directo', 'zh' => '📊 实时结果', 'ja' => '📊 リアルタイム結果', 'pl' => '📊 Wyniki na żywo'],
    'poll.thanks'           => ['fr' => '✅ Merci pour votre vote !', 'en' => '✅ Thanks for your vote!', 'de' => '✅ Danke für deine Stimme!', 'es' => '✅ ¡Gracias por tu voto!', 'zh' => '✅ 感谢你的投票!', 'ja' => '✅ 投票ありがとうございます!', 'pl' => '✅ Dziękujemy za głos!'],
    'poll.closed.label'     => ['fr' => '🔒 Sondage terminé', 'en' => '🔒 Poll closed', 'de' => '🔒 Umfrage beendet', 'es' => '🔒 Encuesta cerrada', 'zh' => '🔒 投票已结束', 'ja' => '🔒 アンケート終了', 'pl' => '🔒 Ankieta zakończona'],
    'poll.no_votes'         => ['fr' => 'Aucun vote pour le moment. Soyez le premier !', 'en' => 'No votes yet. Be the first!', 'de' => 'Noch keine Stimmen abgegeben. Sei der/die Erste!', 'es' => 'Aún no hay votos. ¡Sé el primero!', 'zh' => '暂无投票。成为第一个!', 'ja' => 'まだ投票はありません。最初の人になろう!', 'pl' => 'Brak głosów. Bądź pierwszy!'],
    'poll.your_vote'        => ['fr' => '✓ votre choix', 'en' => '✓ your choice', 'de' => '✓ deine Wahl', 'es' => '✓ tu elección', 'zh' => '✓ 你的选择', 'ja' => '✓ あなたの選択', 'pl' => '✓ twój wybór'],
    'poll.vote.count'       => ['fr' => '{n} vote(s)', 'en' => '{n} vote(s)', 'de' => '{n} Stimme(n)', 'es' => '{n} voto(s)', 'zh' => '{n} 票', 'ja' => '{n} 票', 'pl' => '{n} głos(ów)'],
    'poll.about'            => ['fr' => 'À propos', 'en' => 'About', 'de' => 'Über', 'es' => 'Acerca de', 'zh' => '关于', 'ja' => '概要', 'pl' => 'O programie'],
    'poll.info'             => ['fr' => 'Informations', 'en' => 'Information', 'de' => 'Informationen', 'es' => 'Información', 'zh' => '信息', 'ja' => '情報', 'pl' => 'Informacje'],
    'poll.info.status'      => ['fr' => 'Statut', 'en' => 'Status', 'de' => 'Status', 'es' => 'Estado', 'zh' => '状态', 'ja' => '状態', 'pl' => 'Status'],
    'poll.info.type'        => ['fr' => 'Type', 'en' => 'Type', 'de' => 'Typ', 'es' => 'Tipo', 'zh' => '类型', 'ja' => '種類', 'pl' => 'Typ'],
    'poll.info.voters'      => ['fr' => 'Votants', 'en' => 'Voters', 'de' => 'Abstimmende', 'es' => 'Votantes', 'zh' => '投票者', 'ja' => '投票者', 'pl' => 'Głosujący'],
    'poll.info.options'     => ['fr' => 'Options', 'en' => 'Options', 'de' => 'Optionen', 'es' => 'Opciones', 'zh' => '选项', 'ja' => '選択肢', 'pl' => 'Opcje'],
    'poll.info.closes'      => ['fr' => 'Clôture', 'en' => 'Closes', 'de' => 'Schließt', 'es' => 'Cierre', 'zh' => '截止', 'ja' => '締切', 'pl' => 'Zamknięcie'],
    'poll.login.title'      => ['fr' => 'Connectez-vous pour voter', 'en' => 'Log in to vote', 'de' => 'Anmelden zum Abstimmen', 'es' => 'Inicia sesión para votar', 'zh' => '登录后投票', 'ja' => '投票するにはログイン', 'pl' => 'Zaloguj się, aby głosować'],
    'poll.login.desc'       => ['fr' => "Ce sondage est ouvert aux membres de l'AEIC. Créez un compte ou connectez-vous pour participer et voir les résultats.", 'en' => 'This poll is open to AEIC members. Create an account or log in to take part and see the results.', 'de' => 'Diese Umfrage ist für AEIC-Mitglieder offen. Erstelle ein Konto oder melde dich an, um teilzunehmen und die Ergebnisse zu sehen.', 'es' => 'Esta encuesta está abierta a los miembros de la AEIC. Crea una cuenta o inicia sesión para participar y ver los resultados.', 'zh' => '此投票面向 AEIC 成员开放。创建账户或登录即可参与并查看结果。', 'ja' => 'このアンケートはAEICメンバー向けです。アカウントを作成するかログインして参加し、結果をご覧ください。', 'pl' => 'Ta ankieta jest otwarta dla członków AEIC. Utwórz konto lub zaloguj się, aby wziąć udział i zobaczyć wyniki.'],
    'poll.login.button'     => ['fr' => 'Se connecter', 'en' => 'Log in', 'de' => 'Anmelden', 'es' => 'Iniciar sesión', 'zh' => '登录', 'ja' => 'ログイン', 'pl' => 'Zaloguj'],
    'poll.login.register'   => ['fr' => 'Créer un compte', 'en' => 'Create account', 'de' => 'Konto erstellen', 'es' => 'Crear cuenta', 'zh' => '创建账户', 'ja' => 'アカウントを作成', 'pl' => 'Utwórz konto'],
    'poll.card.multi'       => ['fr' => '☑️ Multi', 'en' => '☑️ Multi', 'de' => '☑️ Multi', 'es' => '☑️ Múltiple', 'zh' => '☑️ 多选', 'ja' => '☑️ 複数', 'pl' => '☑️ Wiele'],
    'poll.card.single'      => ['fr' => '🔘 Unique', 'en' => '🔘 Single', 'de' => '🔘 Einzeln', 'es' => '🔘 Único', 'zh' => '🔘 单选', 'ja' => '🔘 単一', 'pl' => '🔘 Pojedyncze'],
    'poll.card.options'     => ['fr' => '{n} options', 'en' => '{n} options', 'de' => '{n} Optionen', 'es' => '{n} opciones', 'zh' => '{n} 个选项', 'ja' => '{n} つの選択肢', 'pl' => '{n} opcji'],
    'poll.card.view'        => ['fr' => 'Voir →', 'en' => 'View →', 'de' => 'Ansehen →', 'es' => 'Ver →', 'zh' => '查看 →', 'ja' => '見る →', 'pl' => 'Zobacz →'],
    'poll.card.join'        => ['fr' => 'Participer →', 'en' => 'Take part →', 'de' => 'Teilnehmen →', 'es' => 'Participar →', 'zh' => '参与 →', 'ja' => '参加する →', 'pl' => 'Weź udział →'],

    /* ----------------------------------------------------------------- */
    /*  Galerie                                                          */
    /* ----------------------------------------------------------------- */
    'gallery.eyebrow'       => ['fr' => '📷 Galerie', 'en' => '📷 Gallery', 'de' => '📷 Galerie', 'es' => '📷 Galería', 'zh' => '📷 图库', 'ja' => '📷 ギャラリー', 'pl' => '📷 Galeria'],
    'gallery.title'         => ['fr' => 'Photos des événements', 'en' => 'Event photos', 'de' => 'Veranstaltungsfotos', 'es' => 'Fotos de eventos', 'zh' => '活动照片', 'ja' => 'イベントの写真', 'pl' => 'Zdjęcia z wydarzeń'],
    'gallery.lead'          => ['fr' => "Retrouvez en images les meilleurs moments des événements passés de l'AEIC.", 'en' => 'Relive the best moments of past AEIC events in pictures.', 'de' => 'Erlebe die schönsten Momente vergangener AEIC-Veranstaltungen in Bildern.', 'es' => 'Revive en imágenes los mejores momentos de los eventos pasados de la AEIC.', 'zh' => '用图片回顾 AEIC 过往活动的精彩瞬间。', 'ja' => 'AEICの過去のイベントの最高の瞬間を写真でお楽しみください。', 'pl' => 'Ożywj najlepsze chwile minionych wydarzeń AEIC na zdjęciach.'],
    'gallery.empty'         => ['fr' => 'Aucune photo pour le moment. Revenez bientôt après nos prochains événements !', 'en' => 'No photos yet. Check back after our next events!', 'de' => 'Noch keine Fotos. Schau nach unseren nächsten Veranstaltungen wieder vorbei!', 'es' => 'Ninguna foto por ahora. ¡Vuelve después de nuestros próximos eventos!', 'zh' => '暂无照片。下次活动后敬请回来看!', 'ja' => '写真はまだありません。次のイベントの後にまた来てください!', 'pl' => 'Brak zdjęć. Wracaj po naszych kolejnych wydarzeniach!'],
    'gallery.zoom'          => ['fr' => 'Agrandir', 'en' => 'Zoom', 'de' => 'Vergrößern', 'es' => 'Ampliar', 'zh' => '放大', 'ja' => '拡大', 'pl' => 'Powiększ'],
    'gallery.close'         => ['fr' => 'Fermer', 'en' => 'Close', 'de' => 'Schließen', 'es' => 'Cerrar', 'zh' => '关闭', 'ja' => '閉じる', 'pl' => 'Zamknij'],

    /* ----------------------------------------------------------------- */
    /*  Authentification                                                 */
    /* ----------------------------------------------------------------- */
    'auth.login.eyebrow'    => ['fr' => 'Espace membre', 'en' => 'Member area', 'de' => 'Mitgliederbereich', 'es' => 'Zona de miembro', 'zh' => '会员专区', 'ja' => 'メンバーエリア', 'pl' => 'Strefa członka'],
    'auth.login.title'      => ['fr' => 'Connexion', 'en' => 'Log in', 'de' => 'Anmelden', 'es' => 'Iniciar sesión', 'zh' => '登录', 'ja' => 'ログイン', 'pl' => 'Logowanie'],
    'auth.login.email'      => ['fr' => 'Adresse e-mail', 'en' => 'Email address', 'de' => 'E-Mail-Adresse', 'es' => 'Correo electrónico', 'zh' => '电子邮箱', 'ja' => 'メールアドレス', 'pl' => 'Adres e-mail'],
    'auth.login.password'   => ['fr' => 'Mot de passe', 'en' => 'Password', 'de' => 'Passwort', 'es' => 'Contraseña', 'zh' => '密码', 'ja' => 'パスワード', 'pl' => 'Hasło'],
    'auth.login.forgot'     => ['fr' => 'Mot de passe oublié ?', 'en' => 'Forgot password?', 'de' => 'Passwort vergessen?', 'es' => '¿Olvidaste tu contraseña?', 'zh' => '忘记密码?', 'ja' => 'パスワードをお忘れですか?', 'pl' => 'Nie pamiętasz hasła?'],
    'auth.login.submit'     => ['fr' => 'Se connecter', 'en' => 'Log in', 'de' => 'Anmelden', 'es' => 'Iniciar sesión', 'zh' => '登录', 'ja' => 'ログイン', 'pl' => 'Zaloguj'],
    'auth.login.alt'        => ['fr' => 'Pas encore de compte ?', 'en' => 'No account yet?', 'de' => 'Noch kein Konto?', 'es' => '¿Aún no tienes cuenta?', 'zh' => '还没有账户?', 'ja' => 'まだアカウントをお持ちでないですか?', 'pl' => 'Nie masz jeszcze konta?'],
    'auth.register.eyebrow' => ['fr' => "Rejoins l'AEIC", 'en' => 'Join AEIC', 'de' => 'AEIC beitreten', 'es' => 'Únete a la AEIC', 'zh' => '加入 AEIC', 'ja' => 'AEICに参加', 'pl' => 'Dołącz do AEIC'],
    'auth.register.title'   => ['fr' => 'Créer un compte', 'en' => 'Create an account', 'de' => 'Konto erstellen', 'es' => 'Crear una cuenta', 'zh' => '创建账户', 'ja' => 'アカウントを作成', 'pl' => 'Utwórz konto'],
    'auth.register.firstname' => ['fr' => 'Prénom', 'en' => 'First name', 'de' => 'Vorname', 'es' => 'Nombre', 'zh' => '名字', 'ja' => '名', 'pl' => 'Imię'],
    'auth.register.lastname' => ['fr' => 'Nom', 'en' => 'Last name', 'de' => 'Nachname', 'es' => 'Apellidos', 'zh' => '姓氏', 'ja' => '姓', 'pl' => 'Nazwisko'],
    'auth.register.email.hint' => ['fr' => 'Votre mot de passe temporaire vous sera envoyé par e-mail.', 'en' => 'Your temporary password will be sent by email.', 'de' => 'Dein temporäres Passwort wird per E-Mail gesendet.', 'es' => 'Tu contraseña temporal se enviará por correo electrónico.', 'zh' => '临时密码将通过邮件发送给你。', 'ja' => '仮パスワードがメールで送信されます。', 'pl' => 'Twoje hasło tymczasowe zostanie wysłane e-mailem.'],
    'auth.register.consent' => ['fr' => "J'accepte les {cgu} et la {privacy}.", 'en' => 'I accept the {cgu} and the {privacy}.', 'de' => 'Ich akzeptiere die {cgu} und die {privacy}.', 'es' => 'Acepto los {cgu} y la {privacy}.', 'zh' => '我接受{cgu}和{privacy}。', 'ja' => '{cgu}と{privacy}に同意します。', 'pl' => 'Akceptuję {cgu} oraz {privacy}.'],
    'auth.register.cgu'     => ['fr' => "conditions d'utilisation", 'en' => 'terms of use', 'de' => 'Nutzungsbedingungen', 'es' => 'términos de uso', 'zh' => '使用条款', 'ja' => '利用規約', 'pl' => 'regulamin'],
    'auth.register.consent.privacy' => ['fr' => 'politique de confidentialité', 'en' => 'privacy policy', 'de' => 'Datenschutzrichtlinie', 'es' => 'política de privacidad', 'zh' => '隐私政策', 'ja' => 'プライバシーポリシー', 'pl' => 'politykę prywatności'],
    'auth.register.submit'  => ['fr' => 'Créer mon compte', 'en' => 'Create my account', 'de' => 'Konto erstellen', 'es' => 'Crear mi cuenta', 'zh' => '创建我的账户', 'ja' => 'アカウントを作成', 'pl' => 'Utwórz moje konto'],
    'auth.register.alt'     => ['fr' => 'Déjà inscrit ?', 'en' => 'Already registered?', 'de' => 'Bereits registriert?', 'es' => '¿Ya registrado?', 'zh' => '已经注册?', 'ja' => 'すでに登録済みですか?', 'pl' => 'Już zarejestrowany?'],

    /* ----------------------------------------------------------------- */
    /*  Commun                                                           */
    /* ----------------------------------------------------------------- */
    'common.search'         => ['fr' => 'Rechercher', 'en' => 'Search', 'de' => 'Suchen', 'es' => 'Buscar', 'zh' => '搜索', 'ja' => '検索', 'pl' => 'Szukaj'],
    'common.cancel'         => ['fr' => 'Annuler', 'en' => 'Cancel', 'de' => 'Abbrechen', 'es' => 'Cancelar', 'zh' => '取消', 'ja' => 'キャンセル', 'pl' => 'Anuluj'],
    'common.confirm'        => ['fr' => 'Confirmer', 'en' => 'Confirm', 'de' => 'Bestätigen', 'es' => 'Confirmar', 'zh' => '确认', 'ja' => '確認', 'pl' => 'Potwierdź'],
    'common.delete'         => ['fr' => 'Supprimer', 'en' => 'Delete', 'de' => 'Löschen', 'es' => 'Eliminar', 'zh' => '删除', 'ja' => '削除', 'pl' => 'Usuń'],
    'common.save'           => ['fr' => 'Enregistrer', 'en' => 'Save', 'de' => 'Speichern', 'es' => 'Guardar', 'zh' => '保存', 'ja' => '保存', 'pl' => 'Zapisz'],
    'common.back'           => ['fr' => 'Retour', 'en' => 'Back', 'de' => 'Zurück', 'es' => 'Volver', 'zh' => '返回', 'ja' => '戻る', 'pl' => 'Wstecz'],
    'common.see_more'       => ['fr' => 'Voir plus', 'en' => 'See more', 'de' => 'Mehr anzeigen', 'es' => 'Ver más', 'zh' => '查看更多', 'ja' => 'もっと見る', 'pl' => 'Zobacz więcej'],
    'common.download'       => ['fr' => 'Télécharger', 'en' => 'Download', 'de' => 'Herunterladen', 'es' => 'Descargar', 'zh' => '下载', 'ja' => 'ダウンロード', 'pl' => 'Pobierz'],
    'common.details'        => ['fr' => 'Détails', 'en' => 'Details', 'de' => 'Details', 'es' => 'Detalles', 'zh' => '详情', 'ja' => '詳細', 'pl' => 'Szczegóły'],
    'common.featured'       => ['fr' => 'À la une', 'en' => 'Featured', 'de' => 'Im Fokus', 'es' => 'Destacado', 'zh' => '精选', 'ja' => '注目', 'pl' => 'Wyróżnione'],

    /* ----------------------------------------------------------------- */
    /*  Métadonnées de langues (pour le sélecteur)                       */
    /* ----------------------------------------------------------------- */
    'lang.fr' => ['fr' => 'Français', 'en' => 'French', 'de' => 'Französisch', 'es' => 'Francés', 'zh' => '法语', 'ja' => 'フランス語', 'pl' => 'Francuski'],
    'lang.en' => ['fr' => 'Anglais', 'en' => 'English', 'de' => 'Englisch', 'es' => 'Inglés', 'zh' => '英语', 'ja' => '英語', 'pl' => 'Angielski'],
    'lang.de' => ['fr' => 'Allemand', 'en' => 'German', 'de' => 'Deutsch', 'es' => 'Alemán', 'zh' => '德语', 'ja' => 'ドイツ語', 'pl' => 'Niemiecki'],
    'lang.es' => ['fr' => 'Espagnol', 'en' => 'Spanish', 'de' => 'Spanisch', 'es' => 'Español', 'zh' => '西班牙语', 'ja' => 'スペイン語', 'pl' => 'Hiszpański'],
    'lang.zh' => ['fr' => 'Chinois', 'en' => 'Chinese', 'de' => 'Chinesisch', 'es' => 'Chino', 'zh' => '中文', 'ja' => '中国語', 'pl' => 'Chiński'],
    'lang.ja' => ['fr' => 'Japonais', 'en' => 'Japanese', 'de' => 'Japanisch', 'es' => 'Japonés', 'zh' => '日语', 'ja' => '日本語', 'pl' => 'Japoński'],
    'lang.pl' => ['fr' => 'Polonais', 'en' => 'Polish', 'de' => 'Polnisch', 'es' => 'Polaco', 'zh' => '波兰语', 'ja' => 'ポーランド語', 'pl' => 'Polski'],
];
