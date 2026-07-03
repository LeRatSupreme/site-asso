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

    /* ---- Produits cafétéria ---- */
    'Red Bull' => ['en' => 'Red Bull', 'de' => 'Red Bull', 'es' => 'Red Bull', 'zh' => '红牛', 'ja' => 'レッドブル', 'pl' => 'Red Bull', 'ru' => 'Ред Булл', 'ms' => 'Red Bull'],
    'Kinder Bueno' => ['en' => 'Kinder Bueno', 'de' => 'Kinder Bueno', 'es' => 'Kinder Bueno', 'zh' => '健达缤纷乐', 'ja' => 'キンダーブエノ', 'pl' => 'Kinder Bueno', 'ru' => 'Киндер Буэно', 'ms' => 'Kinder Bueno'],
    'Eau' => ['en' => 'Water', 'de' => 'Wasser', 'es' => 'Agua', 'zh' => '水', 'ja' => '水', 'pl' => 'Woda', 'ru' => 'Вода', 'ms' => 'Air'],
    'Monster' => ['en' => 'Monster', 'de' => 'Monster', 'es' => 'Monster', 'zh' => '魔爪', 'ja' => 'モンスター', 'pl' => 'Monster', 'ru' => 'Монстр', 'ms' => 'Monster'],
    'Coca-Cola' => ['en' => 'Coca-Cola', 'de' => 'Coca-Cola', 'es' => 'Coca-Cola', 'zh' => '可口可乐', 'ja' => 'コカ・コーラ', 'pl' => 'Coca-Cola', 'ru' => 'Кока-Кола', 'ms' => 'Coca-Cola'],
    'Oasis' => ['en' => 'Oasis', 'de' => 'Oasis', 'es' => 'Oasis', 'zh' => '绿洲', 'ja' => 'オアシス', 'pl' => 'Oasis', 'ru' => 'Оазис', 'ms' => 'Oasis'],
    'Fanta' => ['en' => 'Fanta', 'de' => 'Fanta', 'es' => 'Fanta', 'zh' => '芬达', 'ja' => 'ファンタ', 'pl' => 'Fanta', 'ru' => 'Фанта', 'ms' => 'Fanta'],
    'Minute Maid' => ['en' => 'Minute Maid', 'de' => 'Minute Maid', 'es' => 'Minute Maid', 'zh' => '美汁源', 'ja' => 'ミニッツメイド', 'pl' => 'Minute Maid', 'ru' => 'Минут Мейд', 'ms' => 'Minute Maid'],
    'Lipton' => ['en' => 'Lipton', 'de' => 'Lipton', 'es' => 'Lipton', 'zh' => '立顿', 'ja' => 'リプトン', 'pl' => 'Lipton', 'ru' => 'Липтон', 'ms' => 'Lipton'],
    'Orangina' => ['en' => 'Orangina', 'de' => 'Orangina', 'es' => 'Orangina', 'zh' => '新奇士', 'ja' => 'オランジーナ', 'pl' => 'Orangina', 'ru' => 'Оранжина', 'ms' => 'Orangina'],
    'Dr Pepper' => ['en' => 'Dr Pepper', 'de' => 'Dr Pepper', 'es' => 'Dr Pepper', 'zh' => '胡椒博士', 'ja' => 'ドクターペッパー', 'pl' => 'Dr Pepper', 'ru' => 'Доктор Пеппер', 'ms' => 'Dr Pepper'],
    'Cristaline' => ['en' => 'Cristaline', 'de' => 'Cristaline', 'es' => 'Cristaline', 'zh' => '克里斯塔琳', 'ja' => 'クリスタリン', 'pl' => 'Cristaline', 'ru' => 'Кристалин', 'ms' => 'Cristaline'],
    'Perrier' => ['en' => 'Perrier', 'de' => 'Perrier', 'es' => 'Perrier', 'zh' => '巴黎水', 'ja' => 'ペリエ', 'pl' => 'Perrier', 'ru' => 'Перье', 'ms' => 'Perrier'],
    'Schweppes' => ['en' => 'Schweppes', 'de' => 'Schweppes', 'es' => 'Schweppes', 'zh' => '怡泉', 'ja' => 'シュウェップス', 'pl' => 'Schweppes', 'ru' => 'Швеппс', 'ms' => 'Schweppes'],
    'Bonbon' => ['en' => 'Candy', 'de' => 'Bonbon', 'es' => 'Caramelo', 'zh' => '糖果', 'ja' => 'キャンディ', 'pl' => 'Cukierek', 'ru' => 'Конфета', 'ms' => 'Gula-gula'],
    'Crêpe' => ['en' => 'Pancake', 'de' => 'Crêpe', 'es' => 'Crep', 'zh' => '可丽饼', 'ja' => 'クレープ', 'pl' => 'Naleśnik', 'ru' => 'Блинчик', 'ms' => 'Krep'],
    'Chips' => ['en' => 'Chips', 'de' => 'Chips', 'es' => 'Patatas', 'zh' => '薯片', 'ja' => 'ポテトチップス', 'pl' => 'Chipsy', 'ru' => 'Чипсы', 'ms' => 'Kerepek'],
    'Lion' => ['en' => 'Lion', 'de' => 'Lion', 'es' => 'Lion', 'zh' => '雄狮', 'ja' => 'ライオン', 'pl' => 'Lion', 'ru' => 'Лион', 'ms' => 'Lion'],
    'Mister Freeze' => ['en' => 'Ice pop', 'de' => 'Eis am Stiel', 'es' => 'Polo helado', 'zh' => '冰棒', 'ja' => 'アイスポップ', 'pl' => 'Lody', 'ru' => 'Лёд на палочке', 'ms' => 'Ais pop'],
    'Menu BBQ' => ['en' => 'BBQ Menu', 'de' => 'Grillmenü', 'es' => 'Menú BBQ', 'zh' => '烧烤套餐', 'ja' => 'BBQセット', 'pl' => 'Menu grill', 'ru' => 'Меню BBQ', 'ms' => 'Menu BBQ'],
    'Boisson énergisante' => ['en' => 'Energy drink', 'de' => 'Energydrink', 'es' => 'Bebida energética', 'zh' => '能量饮料', 'ja' => 'エナジードリンク', 'pl' => 'Napój energetyczny', 'ru' => 'Энергетик', 'ms' => 'Minuman tenaga'],
    'Soda classique' => ['en' => 'Classic soda', 'de' => 'Klassische Limonade', 'es' => 'Refresco clásico', 'zh' => '经典汽水', 'ja' => 'クラシックソーダ', 'pl' => 'Klasyczny napój', 'ru' => 'Классическая газировка', 'ms' => 'Soda klasik'],
    'Jus de fruits' => ['en' => 'Fruit juice', 'de' => 'Fruchtsaft', 'es' => 'Zumo de fruta', 'zh' => '果汁', 'ja' => 'フルーツジュース', 'pl' => 'Sok owocowy', 'ru' => 'Фруктовый сок', 'ms' => 'Jus buah'],
    'Thé glacé' => ['en' => 'Iced tea', 'de' => 'Eistee', 'es' => 'Té helado', 'zh' => '冰茶', 'ja' => 'アイスティー', 'pl' => 'Mrożona herbata', 'ru' => 'Холодный чай', 'ms' => 'Teh ais'],
    'Soda orangé' => ['en' => 'Orange soda', 'de' => 'Orangenlimonade', 'es' => 'Refresco de naranja', 'zh' => '橙味汽水', 'ja' => 'オレンジソーダ', 'pl' => 'Pomarańczowy napój', 'ru' => 'Апельсиновая газировка', 'ms' => 'Soda oren'],
    'Bonbons assorted' => ['en' => 'Assorted candy', 'de' => 'Bonbonmischung', 'es' => 'Caramelos surtidos', 'zh' => '什锦糖果', 'ja' => 'アソートキャンディ', 'pl' => 'Mix cukierków', 'ru' => 'Ассорти конфет', 'ms' => 'Gula-gula campuran'],
    'Crêpe sucrée' => ['en' => 'Sweet pancake', 'de' => 'Süßer Crêpe', 'es' => 'Crep dulce', 'zh' => '甜可丽饼', 'ja' => '甘いクレープ', 'pl' => 'Słodki naleśnik', 'ru' => 'Сладкий блинчик', 'ms' => 'Krep manis'],
    'Paquet de chips' => ['en' => 'Bag of chips', 'de' => 'Chips-Packung', 'es' => 'Bolsa de patatas', 'zh' => '一包薯片', 'ja' => 'ポテトチップス袋', 'pl' => 'Paczka chipsów', 'ru' => 'Пачка чипсов', 'ms' => 'Peket kerepek'],
    'Glace sur bâton' => ['en' => 'Ice pop', 'de' => 'Eis am Stiel', 'es' => 'Polo helado', 'zh' => '冰棒', 'ja' => 'アイスキャンディ', 'pl' => 'Lody na patyku', 'ru' => 'Мороженое на палочке', 'ms' => 'Ais krim tongkat'],
    'Menu barbecue complet' => ['en' => 'Full BBQ menu', 'de' => 'Komplettes Grillmenü', 'es' => 'Menú BBQ completo', 'zh' => '完整烧烤套餐', 'ja' => 'BBQフルセット', 'pl' => 'Pełne menu grill', 'ru' => 'Полное меню BBQ', 'ms' => 'Menu BBQ penuh'],
    'Boissons' => ['en' => 'Drinks', 'de' => 'Getränke', 'es' => 'Bebidas', 'zh' => '饮料', 'ja' => 'ドリンク', 'pl' => 'Napoje', 'ru' => 'Напитки', 'ms' => 'Minuman'],
    'Snacks' => ['en' => 'Snacks', 'de' => 'Snacks', 'es' => 'Aperitivos', 'zh' => '零食', 'ja' => 'スナック', 'pl' => 'Przekąski', 'ru' => 'Закуски', 'ms' => 'Snek'],
    'Spécial' => ['en' => 'Special', 'de' => 'Spezial', 'es' => 'Especial', 'zh' => '特色', 'ja' => 'スペシャル', 'pl' => 'Specjalne', 'ru' => 'Специальное', 'ms' => 'Istimewa'],

    /* ---- Rôles du bureau ---- */
    'Président' => ['en' => 'President', 'de' => 'Präsident', 'es' => 'Presidente', 'zh' => '主席', 'ja' => '会長', 'pl' => 'Prezes', 'ru' => 'Председатель', 'ms' => 'Presiden'],
    'Vice-Président' => ['en' => 'Vice President', 'de' => 'Vizepräsident', 'es' => 'Vicepresidente', 'zh' => '副主席', 'ja' => '副会長', 'pl' => 'Wiceprezes', 'ru' => 'Заместитель председателя', 'ms' => 'Timbalan Presiden'],
    'Trésorier' => ['en' => 'Treasurer', 'de' => 'Kassenwart', 'es' => 'Tesorero', 'zh' => '财务主管', 'ja' => '会計', 'pl' => 'Skarbnik', 'ru' => 'Казначей', 'ms' => 'Bendahara'],
    'Vice-Trésorier' => ['en' => 'Vice Treasurer', 'de' => 'Vize-Kassenwart', 'es' => 'Vicetesorero', 'zh' => '副财务主管', 'ja' => '副会計', 'pl' => 'Wiceskarbnik', 'ru' => 'Заместитель казначея', 'ms' => 'Timbalan Bendahara'],
    'Secrétaire' => ['en' => 'Secretary', 'de' => 'Schriftführer', 'es' => 'Secretario', 'zh' => '秘书', 'ja' => '書記', 'pl' => 'Sekretarz', 'ru' => 'Секретарь', 'ms' => 'Setiausaha'],
    'Vice-Secrétaire' => ['en' => 'Vice Secretary', 'de' => 'Vize-Schriftführer', 'es' => 'Vicesecretario', 'zh' => '副秘书长', 'ja' => '副書記', 'pl' => 'Wicesekretarz', 'ru' => 'Заместитель секретаря', 'ms' => 'Timbalan Setiausaha'],
    'Responsable Communication' => ['en' => 'Communication Manager', 'de' => 'Kommunikationsverantwortlicher', 'es' => 'Responsable de Comunicación', 'zh' => '宣传负责人', 'ja' => '広報担当', 'pl' => 'Odpowiedzialny za komunikację', 'ru' => 'Ответственный за связь', 'ms' => 'Pengurus Komunikasi'],
    'Responsable Événements' => ['en' => 'Events Manager', 'de' => 'Veranstaltungsverantwortlicher', 'es' => 'Responsable de Eventos', 'zh' => '活动负责人', 'ja' => 'イベント担当', 'pl' => 'Odpowiedzialny za wydarzenia', 'ru' => 'Ответственный за мероприятия', 'ms' => 'Pengurus Acara'],
    'Responsable Cafétéria' => ['en' => 'Cafeteria Manager', 'de' => 'Cafeteria-Verantwortlicher', 'es' => 'Responsable de Cafetería', 'zh' => '咖啡厅负责人', 'ja' => 'カフェテリア担当', 'pl' => 'Odpowiedzialny za kafeterię', 'ru' => 'Ответственный за кафетерий', 'ms' => 'Pengurus Kafeteria'],
    'Membre' => ['en' => 'Member', 'de' => 'Mitglied', 'es' => 'Miembro', 'zh' => '成员', 'ja' => 'メンバー', 'pl' => 'Członek', 'ru' => 'Член', 'ms' => 'Ahli'],

    /* ---- Pôles ---- */
    'bureau' => ['en' => 'Board', 'de' => 'Vorstand', 'es' => 'Junta', 'zh' => '核心管理层', 'ja' => '役員会', 'pl' => 'Zarząd', 'ru' => 'Правление', 'ms' => 'Jawatankuasa'],
    'communication' => ['en' => 'Communication', 'de' => 'Kommunikation', 'es' => 'Comunicación', 'zh' => '宣传部', 'ja' => '広報部', 'pl' => 'Komunikacja', 'ru' => 'Связь', 'ms' => 'Komunikasi'],
    'événements' => ['en' => 'Events', 'de' => 'Veranstaltungen', 'es' => 'Eventos', 'zh' => '活动部', 'ja' => 'イベント部', 'pl' => 'Wydarzenia', 'ru' => 'Мероприятия', 'ms' => 'Acara'],
    'cafétéria' => ['en' => 'Cafeteria', 'de' => 'Cafeteria', 'es' => 'Cafetería', 'zh' => '咖啡厅', 'ja' => 'カフェテリア', 'pl' => 'Kafeteria', 'ru' => 'Кафетерий', 'ms' => 'Kafeteria'],

    /* ---- Bios ---- */
    'Gère les finances, les paiements et la comptabilité.' => ['en' => 'Manages finances, payments and accounting.', 'de' => 'Verwaltet die Finanzen, Zahlungen und Buchhaltung.', 'es' => 'Gestiona las finanzas, los pagos y la contabilidad.', 'zh' => '管理财务、付款和会计。', 'ja' => '財務、支払い、会計を管理します。', 'pl' => 'Zarządza finansami, płatnościami i księgowością.', 'ru' => 'Управляет финансами, платежами и бухгалтерией.', 'ms' => 'Mengurus kewangan, pembayaran dan perakaunan.'],

];
