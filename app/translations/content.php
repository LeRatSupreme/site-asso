<?php

declare(strict_types=1);

/**
 * Overlay de traduction pour le contenu dynamique (titres, extraits, lieux,
 * settings).
 *
 * Approche : la clé est le texte français EXACT tel qu'il est stocké en base
 * (events.title, events.excerpt, events.location, settings.site_description...).
 * La valeur est un tableau associatif [lang => traduction].
 *
 * Le français n'est jamais listé en valeur : c'est la clé elle-même qui sert
 * de texte de référence (et de fallback via tc()).
 *
 * Langues : en, de, es, zh, ja, pl, ru, ms.
 */

return [

    /* ----------------------------------------------------------------- */
    /*  Settings                                                         */
    /* ----------------------------------------------------------------- */
    'Association Étudiante Informatique de Calais. Fait par les étudiants, pour les étudiants.' => [
        'en' => 'Student Computer Science Association of Calais. Made by students, for students.',
        'de' => 'Studentischer Informatikverein Calais. Von Studierenden, für Studierende.',
        'es' => 'Asociación Estudiantil de Informática de Calais. Hecho por estudiantes, para estudiantes.',
        'zh' => '加来计算机学生协会。由学生打造,为学生服务。',
        'ja' => 'カレー学生情報科学協会。学生による、学生のための活動。',
        'pl' => 'Studenckie Stowarzyszenie Informatyczne Calais. Tworzone przez studentów, dla studentów.',
        'ru' => 'Студенческая ассоциация информатики Кале. Сделано студентами для студентов.',
        'ms' => 'Persatuan Informatik Pelajar Calais. Dibuat oleh pelajar, untuk pelajar.',
    ],

    /* ----------------------------------------------------------------- */
    /*  Titres d'événements                                              */
    /* ----------------------------------------------------------------- */
    'Soirée d\'intégration' => [
        'en' => 'Welcome party',
        'de' => 'Begrüßungsfeier',
        'es' => 'Fiesta de bienvenida',
        'zh' => '迎新晚会',
        'ja' => '新歓パーティー',
        'pl' => 'Wieczór integracyjny',
        'ru' => 'Вечеринка знакомства',
        'ms' => 'Malam mesra',
    ],

    // Variation orthographique possible (sans accent / apostrophe droite).
    'Soiree d\'integration' => [
        'en' => 'Welcome party',
        'de' => 'Begrüßungsfeier',
        'es' => 'Fiesta de bienvenida',
        'zh' => '迎新晚会',
        'ja' => '新歓パーティー',
        'pl' => 'Wieczór integracyjny',
        'ru' => 'Вечеринка знакомства',
        'ms' => 'Malam mesra',
    ],

    'LAN Party' => [
        'en' => 'LAN Party',
        'de' => 'LAN Party',
        'es' => 'LAN Party',
        'zh' => 'LAN 派对',
        'ja' => 'LANパーティー',
        'pl' => 'LAN Party',
        'ru' => 'LAN-вечеринка',
        'ms' => 'Parti LAN',
    ],

    'Conférence IA' => [
        'en' => 'AI Conference',
        'de' => 'KI-Konferenz',
        'es' => 'Conferencia de IA',
        'zh' => '人工智能讲座',
        'ja' => 'AI カンファレンス',
        'pl' => 'Konferencja AI',
        'ru' => 'Конференция по ИИ',
        'ms' => 'Persidangan AI',
    ],

    'Afterwork de rentrée' => [
        'en' => 'Back-to-school Afterwork',
        'de' => 'Semesterstart-Afterwork',
        'es' => 'Afterwork de inicio de curso',
        'zh' => '开学职场社交会',
        'ja' => '新学期アフターワーク',
        'pl' => 'Afterwork na start semestru',
        'ru' => 'Afterwork к началу года',
        'ms' => 'Afterwork kembali ke kampus',
    ],

    'Barbecue de rentrée' => [
        'en' => 'Welcome BBQ',
        'de' => 'Begrüßungsgrillen',
        'es' => 'Barbacoa de bienvenida',
        'zh' => '迎新烧烤',
        'ja' => '新歓バーベキュー',
        'pl' => 'Powitalne grille',
        'ru' => 'Приветственный барбекю',
        'ms' => 'BBQ sambutan',
    ],

    'Soirée bowling' => [
        'en' => 'Bowling night',
        'de' => 'Bowling-Abend',
        'es' => 'Noche de bolos',
        'zh' => '保龄球之夜',
        'ja' => 'ボウリングの夕べ',
        'pl' => 'Wieczór kręgli',
        'ru' => 'Боулинг-вечер',
        'ms' => 'Malam boling',
    ],

    'Nuit de l\'Info' => [
        // Nom propre : événement national français, on garde l'appellation.
        'en' => 'Nuit de l\'Info',
        'de' => 'Nuit de l\'Info',
        'es' => 'Nuit de l\'Info',
        'zh' => 'Nuit de l\'Info',
        'ja' => 'Nuit de l\'Info',
        'pl' => 'Nuit de l\'Info',
        'ru' => 'Nuit de l\'Info',
        'ms' => 'Nuit de l\'Info',
    ],

    'Hackathon' => [
        'en' => 'Hackathon',
        'de' => 'Hackathon',
        'es' => 'Hackathon',
        'zh' => '黑客松',
        'ja' => 'ハッカソン',
        'pl' => 'Hackathon',
        'ru' => 'Хакатон',
        'ms' => 'Hackathon',
    ],

    /* ----------------------------------------------------------------- */
    /*  Extraits d'événements                                            */
    /* ----------------------------------------------------------------- */
    'Le rendez-vous de rentrée de tous les étudiants en info.' => [
        'en' => 'The back-to-school get-together for all CS students.',
        'de' => 'Das Semesterstart-Treffen für alle Informatik-Studierenden.',
        'es' => 'El encuentro de inicio de curso para todos los estudiantes de informática.',
        'zh' => '全体计算机学生的开学聚会。',
        'ja' => '全情報学生のための新学期の集い。',
        'pl' => 'Powitalne spotkanie wszystkich studentów informatyki.',
        'ru' => 'Встреча начала года для всех студентов-информатиков.',
        'ms' => 'Perjumpaan kembali ke kampus untuk semua pelajar informatik.',
    ],

    'Tournois jeux vidéo toute la nuit.' => [
        'en' => 'Video game tournaments all night long.',
        'de' => 'VideoSpiel-Turniere die ganze Nacht.',
        'es' => 'Torneos de videojuegos durante toda la noche.',
        'zh' => '整晚的电子游戏锦标赛。',
        'ja' => '一晩中のゲーム大会。',
        'pl' => 'Turnieje gier wideo przez całą noc.',
        'ru' => 'Турниры по видеоиграм всю ночь.',
        'ms' => 'Kejohanan permainan video sepanjang malam.',
    ],

    'Intervenants pro autour de l\'IA générative.' => [
        'en' => 'Professional speakers on generative AI.',
        'de' => 'Fachleute zum Thema generative KI.',
        'es' => 'Profesionales invitados en torno a la IA generativa.',
        'zh' => '围绕生成式人工智能的专业演讲嘉宾。',
        'ja' => '生成AIをめぐるプロの登壇者。',
        'pl' => 'Profesjonalni prelegenci o generatywnej sztucznej inteligencji.',
        'ru' => 'Профессиональные спикеры о генеративном ИИ.',
        'ms' => 'Penceramah profesional tentang AI generatif.',
    ],

    'Décompressez après les cours et faites-vous des amis autour d\'un verre.' => [
        'en' => 'Unwind after class and make friends over a drink.',
        'de' => 'Nach den Vorlesungen abschalten und bei einem Drink Freunde finden.',
        'es' => 'Desconecta después de clase y haz amigos alrededor de una bebida.',
        'zh' => '课后放松,在杯酒之间结交朋友。',
        'ja' => '授業の後にリラックスして、一杯囲みながら友達を作ろう。',
        'pl' => 'Zrelaksuj się po zajęciach i zdobądź znajomych przy drinku.',
        'ru' => 'Отдохните после занятий и заведите друзей за бокалом напитка.',
        'ms' => 'Bersantai selepas kelas dan berkawan sambil minum.',
    ],

    'Un moment de partage et de convivialité en plein air pour toute la communauté.' => [
        'en' => 'A moment of sharing and conviviality outdoors for the whole community.',
        'de' => 'Ein Moment des Teilens und der Geselligkeit im Freien für die gesamte Gemeinschaft.',
        'es' => 'Un momento de compartir y convivir al aire libre para toda la comunidad.',
        'zh' => '面向全体社区的户外分享与欢聚时刻。',
        'ja' => 'コミュニティ全員のための、屋外での分かち合いと和やかなひととき。',
        'pl' => 'Chwila dzielenia się i wspólnoty na świeżym powietrzu dla całej społeczności.',
        'ru' => 'Момент общения и уюта на свежем воздухе для всего сообщества.',
        'ms' => 'Momen perkongsian dan kebersamaan di luar ruang untuk seluruh komuniti.',
    ],

    'Défiez vos camarades sur les pistes pour une soirée compétitive et fun.' => [
        'en' => 'Challenge your classmates on the lanes for a competitive and fun evening.',
        'de' => 'Fordere deine Kommilitoninnen auf den Bahnen zu einem kompetitiven und lustigen Abend heraus.',
        'es' => 'Desafía a tus compañeros en las pistas para una noche competitiva y divertida.',
        'zh' => '在球道上挑战同学,享受一晚既竞技又欢乐的时光。',
        'ja' => 'レーンで同級生に挑んで、競い合いながら楽しむ夜。',
        'pl' => 'Rywalizuj ze znajomymi na torach w konkurencyjny i wesoły wieczór.',
        'ru' => 'Бросьте вызов однокурсникам на дорожках в соревновательный и весёлый вечер.',
        'ms' => 'Cabari rakan sekelas di lorong untuk malam yang berpersaingan dan menyeronokkan.',
    ],

    'L\'événement national incontournable : une nuit de programmation collaborative pour de bonnes causes.' => [
        'en' => 'The unmissable national event: a night of collaborative programming for good causes.',
        'de' => 'Das unverzichtbare nationale Event: eine Nacht kollaborativen Programmierens für gute Zwecke.',
        'es' => 'El evento nacional imprescindible: una noche de programación colaborativa para buenas causas.',
        'zh' => '不可错过的全国盛事:为公益事业而通宵协作编程。',
        'ja' => '見逃せない全国イベント:良い原因のために協力してコードを書く一晩。',
        'pl' => 'Krajowe wydarzenie, którego nie można przegapić: noc wspólnego programowania na szczytny cel.',
        'ru' => 'Обязательное национальное событие: ночь совместного программирования на благо добрых дел.',
        'ms' => 'Acara kebangsaan yang tidak boleh dilepaskan: satu malam pengaturcaraan kolaboratif untuk tujuan baik.',
    ],

    /* ----------------------------------------------------------------- */
    /*  Lieux                                                            */
    /* ----------------------------------------------------------------- */
    'IUT de Calais — Hall du département Informatique' => [
        'en' => 'IUT de Calais — Computer Science department hall',
        'de' => 'IUT de Calais — Foyer des Fachbereichs Informatik',
        'es' => 'IUT de Calais — Vestíbulo del departamento de Informática',
        'zh' => '加来 IUT — 计算机系大厅',
        'ja' => 'カレー IUT — 情報工学科ホール',
        'pl' => 'IUT de Calais — Hol wydziału Informatyki',
        'ru' => 'IUT de Calais — холл факультета информатики',
        'ms' => 'IUT de Calais — Dewan jabatan Informatik',
    ],

    'Campus de Calais — Amphi A' => [
        'en' => 'Calais Campus — Lecture hall A',
        'de' => 'Campus Calais — Hörsaal A',
        'es' => 'Campus de Calais — Aula magna A',
        'zh' => '加来校区 — A 阶梯教室',
        'ja' => 'カレーキャンパス — 講義棟A',
        'pl' => 'Campus Calais — Aula A',
        'ru' => 'Кампус Кале — Амфи A',
        'ms' => 'Kampus Calais — Dewan kuliah A',
    ],

    'Pelouse de l\'IUT de Calais' => [
        'en' => 'IUT de Calais lawn',
        'de' => 'Rasenfläche des IUT de Calais',
        'es' => 'Césped del IUT de Calais',
        'zh' => '加来 IUT 草坪',
        'ja' => 'カレー IUT の芝生',
        'pl' => 'Trawnik IUT de Calais',
        'ru' => 'Газон IUT de Calais',
        'ms' => 'Lapang IUT de Calais',
    ],

    'Bowling de Calais' => [
        'en' => 'Calais Bowling Alley',
        'de' => 'Bowlingbahn Calais',
        'es' => 'Bolera de Calais',
        'zh' => '加来保龄球馆',
        'ja' => 'カレーのボウリング場',
        'pl' => 'Kręgielnia w Calais',
        'ru' => 'Боулинг Кале',
        'ms' => 'Laluan boling Calais',
    ],

    'Salle des associations' => [
        'en' => 'Student associations room',
        'de' => 'Vereinsraum',
        'es' => 'Sala de asociaciones',
        'zh' => '社团活动室',
        'ja' => 'サークル室',
        'pl' => 'Sala stowarzyszeń studenckich',
        'ru' => 'Зал ассоциаций',
        'ms' => 'Bilik persatuan pelajar',
    ],

    'Amphi B' => [
        'en' => 'Lecture hall B',
        'de' => 'Hörsaal B',
        'es' => 'Aula magna B',
        'zh' => 'B 阶梯教室',
        'ja' => '講義棟B',
        'pl' => 'Aula B',
        'ru' => 'Амфи B',
        'ms' => 'Dewan kuliah B',
    ],

    'IUT de Calais — Salles informatiques' => [
        'en' => 'IUT de Calais — Computer labs',
        'de' => 'IUT de Calais — Computerräume',
        'es' => 'IUT de Calais — Aulas de informática',
        'zh' => '加来 IUT — 计算机机房',
        'ja' => 'カレー IUT — 情報実習室',
        'pl' => 'IUT de Calais — Sale komputerowe',
        'ru' => 'IUT de Calais — компьютерные классы',
        'ms' => 'IUT de Calais — Makmal komputer',
    ],

    /* ---- Notifications ---- */
    'Vous êtes inscrit à « Nuit de l\'Info »' => [
        'en' => 'You are registered for "Nuit de l\'Info"', 'de' => 'Sie sind für "Nuit de l\'Info" angemeldet', 'es' => 'Estás inscrito en "Nuit de l\'Info"', 'zh' => '您已注册参加「信息之夜」', 'ja' => '「Nuit de l\'Info」に登録しました', 'pl' => 'Jesteś zapisany na "Nuit de l\'Info"', 'ru' => 'Вы записаны на «Nuit de l\'Info»', 'ms' => 'Anda berdaftar untuk "Nuit de l\'Info"',
    ],
    'Vous êtes inscrit à « Nuit de l\'info »' => [
        'en' => 'You are registered for "Nuit de l\'Info"', 'de' => 'Sie sind für "Nuit de l\'Info" angemeldet', 'es' => 'Estás inscrito en "Nuit de l\'Info"', 'zh' => '您已注册参加「信息之夜」', 'ja' => '「Nuit de l\'Info」に登録しました', 'pl' => 'Jesteś zapisany na "Nuit de l\'Info"', 'ru' => 'Вы записаны на «Nuit de l\'Info»', 'ms' => 'Anda berdaftar untuk "Nuit de l\'Info"',
    ],
    'Votre inscription est confirmée. Retrouvez les détails dans votre espace.' => [
        'en' => 'Your registration is confirmed. Find the details in your space.', 'de' => 'Ihre Anmeldung ist bestätigt. Details finden Sie in Ihrem Bereich.', 'es' => 'Tu inscripción está confirmada. Encuentra los detalles en tu espacio.', 'zh' => '您的报名已确认。请在个人空间中查看详情。', 'ja' => '登録が確認されました。詳細はスペースでご確認ください。', 'pl' => 'Twoja rejestracja jest potwierdzona. Szczegóły znajdziesz w swoim obszarze.', 'ru' => 'Ваша регистрация подтверждена. Подробности в личном кабинете.', 'ms' => 'Pendaftaran anda disahkan. Sila lihat butiran di ruang anda.',
    ],
    'Vote enregistré pour « Chocolatine ou pain au chocolat ? »' => [
        'en' => 'Vote recorded for "Chocolatine ou pain au chocolat ?"', 'de' => 'Stimme abgegeben für "Chocolatine ou pain au chocolant ?"', 'es' => 'Voto registrado para "Chocolatine ou pain au chocolat ?"', 'zh' => '已为「巧克力面包还是巧克力面包？」投票', 'ja' => '「Chocolatine ou pain au chocolat ?」に投票しました', 'pl' => 'Głos oddany na "Chocolatine ou pain au chocolat ?"', 'ru' => 'Голос записан за «Chocolatine ou pain au chocolat ?»', 'ms' => 'Undi direkodkan untuk "Chocolatine ou pain au chocolat ?"',
    ],
    'Votre vote a bien été pris en compte.' => [
        'en' => 'Your vote has been recorded.', 'de' => 'Ihre Stimme wurde erfasst.', 'es' => 'Tu voto ha sido registrado.', 'zh' => '您的投票已被记录。', 'ja' => '投票が記録されました。', 'pl' => 'Twój głos został zarejestrowany.', 'ru' => 'Ваш голос учтён.', 'ms' => 'Undi anda telah direkodkan.',
    ],
    'Une place s\'est libérée pour' => [
        'en' => 'A spot opened up for', 'de' => 'Ein Platz ist frei geworden für', 'es' => 'Se ha liberado una plaza para', 'zh' => '有空位了：', 'ja' => '空きが出ました：', 'pl' => 'Zwolniło się miejsce na', 'ru' => 'Освободилось место для', 'ms' => 'Satu tempat telah kosong untuk',
    ],
    'Vous êtes inscrit !' => [
        'en' => 'You are registered!', 'de' => 'Sie sind angemeldet!', 'es' => '¡Estás inscrito!', 'zh' => '您已注册！', 'ja' => '登録完了！', 'pl' => 'Jesteś zapisany!', 'ru' => 'Вы записаны!', 'ms' => 'Anda telah berdaftar!',
    ],

];
