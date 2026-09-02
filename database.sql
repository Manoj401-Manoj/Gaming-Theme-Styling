-- ============================================================
-- GAMING WEBSITE BUILDER - Complete Database Schema
-- Single database: gaming_builder
-- ============================================================

CREATE DATABASE IF NOT EXISTS gaming_builder CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gaming_builder;

-- ============================================================
-- USERS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(120) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) DEFAULT '',
    avatar VARCHAR(255) DEFAULT '',
    bio TEXT DEFAULT '',
    role ENUM('user','admin') DEFAULT 'user',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- TEMPLATES TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50) DEFAULT 'gaming',
    color_scheme VARCHAR(50) DEFAULT 'dark',
    accent_color VARCHAR(20) DEFAULT '#8B5CF6',
    preview_tag VARCHAR(30) DEFAULT 'Popular',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- GAMES TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS games (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    title VARCHAR(120) NOT NULL,
    genre VARCHAR(60) DEFAULT '',
    platform VARCHAR(80) DEFAULT '',
    rating DECIMAL(3,1) DEFAULT 0.0,
    release_year INT DEFAULT NULL,
    image_url VARCHAR(255) DEFAULT '',
    description TEXT DEFAULT '',
    is_featured TINYINT(1) DEFAULT 0,
    plays_count INT DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE
);

-- ============================================================
-- NEWS / ARTICLES TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS news_articles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    author_id INT DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(200) NOT NULL,
    excerpt TEXT DEFAULT '',
    content LONGTEXT DEFAULT '',
    category VARCHAR(60) DEFAULT 'News',
    cover_image VARCHAR(255) DEFAULT '',
    views INT DEFAULT 0,
    is_published TINYINT(1) DEFAULT 1,
    published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- GAMING SETUPS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS gaming_setups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    setup_name VARCHAR(120) NOT NULL,
    owner_name VARCHAR(80) DEFAULT '',
    cpu VARCHAR(100) DEFAULT '',
    gpu VARCHAR(100) DEFAULT '',
    ram VARCHAR(60) DEFAULT '',
    storage VARCHAR(100) DEFAULT '',
    monitor VARCHAR(120) DEFAULT '',
    peripherals TEXT DEFAULT '',
    image_url VARCHAR(255) DEFAULT '',
    total_cost VARCHAR(30) DEFAULT '',
    description TEXT DEFAULT '',
    likes_count INT DEFAULT 0,
    is_featured TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- TOURNAMENTS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS tournaments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    game_title VARCHAR(100) DEFAULT '',
    prize_pool VARCHAR(50) DEFAULT '',
    max_teams INT DEFAULT 16,
    registered_teams INT DEFAULT 0,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    status ENUM('upcoming','ongoing','completed') DEFAULT 'upcoming',
    description TEXT DEFAULT '',
    image_url VARCHAR(255) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE
);

-- ============================================================
-- USER SETTINGS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS user_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    email_notifications TINYINT(1) DEFAULT 1,
    newsletter TINYINT(1) DEFAULT 0,
    dark_mode TINYINT(1) DEFAULT 1,
    language VARCHAR(10) DEFAULT 'en',
    privacy ENUM('public','friends','private') DEFAULT 'public',
    two_factor TINYINT(1) DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- COMMENTS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    article_id INT NOT NULL,
    content TEXT NOT NULL,
    is_approved TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (article_id) REFERENCES news_articles(id) ON DELETE CASCADE
);

-- ============================================================
-- CONTACT MESSAGES TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL,
    subject VARCHAR(200) DEFAULT '',
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- SEED DATA - Templates
-- ============================================================
INSERT INTO templates (slug, name, description, color_scheme, accent_color, preview_tag, sort_order) VALUES
('cyberneon', 'CyberNeon', 'A futuristic cyberpunk gaming site with neon glow effects, dark backgrounds, and immersive sci-fi aesthetics. Perfect for tech-focused gaming communities.', 'cyberpunk', '#A855F7', 'Most Popular', 1),
('bladearena', 'BladeArena', 'A fierce esports tournament platform with bold red and black color scheme, team rosters, bracket systems, and live match tracking.', 'esports', '#EF4444', 'Esports', 2),
('mythquest', 'MythQuest', 'An epic fantasy RPG portal with ornate golden accents, ancient textures, and immersive lore sections for fantasy gaming enthusiasts.', 'fantasy', '#F59E0B', 'Fantasy', 3),
('pixelvault', 'PixelVault', 'A retro-inspired arcade vault celebrating classic and indie gaming with pixel art aesthetics, vibrant colors, and nostalgic design.', 'retro', '#10B981', 'Retro', 4);

-- ============================================================
-- SEED DATA - Admin User (password: Admin@1234)
-- ============================================================
INSERT INTO users (username, email, password, full_name, role, bio) VALUES
('admin', 'admin@gamingbuilder.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin User', 'admin', 'Platform administrator'),
('gamer_alex', 'alex@example.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Alex Thompson', 'user', 'Hardcore gamer & streamer. PC master race enthusiast.'),
('nova_striker', 'nova@example.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nova Striker', 'user', 'Pro esports player. Team captain at BladeArena tournaments.');

-- ============================================================
-- SEED DATA - Games per Template
-- ============================================================

-- CyberNeon Games
INSERT INTO games (template_id, title, genre, platform, rating, release_year, description, is_featured, plays_count) VALUES
(1, 'Cyberstrike 2077', 'Action RPG', 'PC, PS5, Xbox', 9.4, 2024, 'A breathtaking open-world cyberpunk RPG set in Night City 2077. Hack, upgrade, and fight your way through a dystopian megacity.', 1, 542000),
(1, 'NeonBlade Online', 'MMORPG', 'PC', 8.8, 2023, 'A massive multiplayer online world with neon-lit cities, futuristic combat, and deep character progression systems.', 1, 380000),
(1, 'VoidRunner X', 'Shooter', 'PC, PS5', 8.2, 2024, 'A fast-paced sci-fi shooter in zero gravity environments with stunning visual effects and intense multiplayer modes.', 0, 210000),
(1, 'Neural Drift', 'Racing', 'PC, Xbox', 8.5, 2023, 'A high-speed futuristic racing game where you pilot neural-linked vehicles through impossible tracks at light speed.', 0, 165000),
(1, 'Synapse Strike', 'Strategy', 'PC', 7.9, 2024, 'A cyberpunk real-time strategy where you hack enemy systems and deploy drone armies across a neon-soaked battlefield.', 0, 98000);

-- BladeArena Games
INSERT INTO games (template_id, title, genre, platform, rating, release_year, description, is_featured, plays_count) VALUES
(2, 'BladeStorm Championship', 'Fighting', 'PC, PS5, Xbox', 9.6, 2024, 'The ultimate competitive fighting game. Master 60+ characters, dominate ranked ladders, and compete in global tournaments.', 1, 892000),
(2, 'Arena Force Pro', 'Battle Royale', 'PC, Mobile', 9.1, 2023, 'A tactical battle royale designed for esports. Precision mechanics, team-based strategies, and pro-grade ranked modes.', 1, 1200000),
(2, 'SiegeWar Elite', 'FPS', 'PC', 8.9, 2024, 'A military-grade tactical FPS with destructible environments, realistic ballistics, and a thriving esports scene.', 0, 445000),
(2, 'Overdrive Racing League', 'Racing', 'PC, PS5', 8.3, 2023, 'The premier esports racing league simulation. Real car physics, team management, and championship seasons.', 0, 230000);

-- MythQuest Games
INSERT INTO games (template_id, title, genre, platform, rating, release_year, description, is_featured, plays_count) VALUES
(3, 'Legends of Aethoria', 'MMORPG', 'PC', 9.5, 2023, 'An epic fantasy MMO with 10,000+ quests, 200+ dungeons, and a living world shaped by player decisions. Build your legend.', 1, 680000),
(3, 'Dragon Throne Conquest', 'Strategy RPG', 'PC, Mobile', 9.0, 2024, 'Command armies, forge alliances, and claim the Dragon Throne in this immersive fantasy strategy RPG.', 1, 450000),
(3, 'Shadowveil Chronicles', 'Action RPG', 'PC, PS5', 8.7, 2023, 'A dark fantasy action RPG with Soulslike combat, rich lore, and a mysterious world shrouded in eternal shadow.', 0, 310000),
(3, 'Enchanted Realms Online', 'MMORPG', 'PC', 8.2, 2024, 'A lighthearted fantasy MMO featuring vibrant worlds, companion systems, and thousands of crafting recipes.', 0, 195000);

-- PixelVault Games
INSERT INTO games (template_id, title, genre, platform, rating, release_year, description, is_featured, plays_count) VALUES
(4, 'RetroBlast Ultimate', 'Arcade', 'PC, Switch', 9.2, 2024, 'The definitive retro shoot-em-up collection featuring 500+ classic arcade games remastered in glorious pixel art.', 1, 320000),
(4, 'PixelQuest Adventure', 'Platformer', 'PC, Switch, Mobile', 8.9, 2023, 'A charming pixel art platformer with 100+ levels, hidden secrets, and a heartwarming 16-bit adventure.', 1, 275000),
(4, 'ChipTune Racer', 'Racing', 'PC, Mobile', 8.1, 2024, 'A colorful retro racing game with chiptune soundtrack, pixel tracks, and an extensive garage of 8-bit cars.', 0, 140000),
(4, 'Dungeon Digger Classic', 'Roguelike', 'PC, Switch', 8.6, 2023, 'A procedurally generated pixel dungeon crawler with permadeath, loot systems, and endless replayability.', 0, 210000);

-- ============================================================
-- SEED DATA - News Articles
-- ============================================================

-- CyberNeon News
INSERT INTO news_articles (template_id, author_id, title, slug, excerpt, content, category, views) VALUES
(1, 1, 'Cyberstrike 2077 Expansion "Nova Protocol" Launches Next Month', 'cyberstrike-nova-protocol', 'The massive expansion brings new districts, storylines, and cyber-augmentations to Night City.', 'The highly anticipated Nova Protocol expansion for Cyberstrike 2077 is set to launch next month, promising to double the size of Night City with entirely new districts, storylines, and game-changing cyber-augmentations. Players can expect over 40 hours of new content, including a sprawling new corporate district, underground black markets, and a branching narrative that responds to your previous choices in the base game. New augmentations include neural hacking, exo-skeleton enhancements, and optical implants that reveal hidden pathways throughout the world.', 'Updates', 45200),
(1, 2, 'NeonBlade Online Season 12 Brings New Class: The Phantom Weaver', 'neonblade-season-12-phantom', 'Season 12 introduces the most requested class since launch with unique stealth mechanics.', 'Season 12 of NeonBlade Online launches this week with the long-awaited Phantom Weaver class, a stealth-based assassin that manipulates light and shadow to become nearly invisible in combat. The class features a unique dual-resource system balancing Shadow Energy and Light Essence, allowing for devastating burst combos when properly managed. New raid content, a revamped crafting system, and a brand new server region in Southeast Asia also arrive with the season update.', 'Updates', 28900),
(1, 1, 'VoidRunner X Tournament Series Announces $500K Prize Pool', 'voidrunner-tournament-500k', 'The VoidRunner Championship Series returns with its biggest prize pool in history.', 'The VoidRunner Championship Series is back with an enormous $500,000 prize pool distributed across regional qualifiers and a grand finals event in Tokyo, Japan. Teams from 48 countries are eligible to compete in the qualification rounds, which begin online next month. The tournament will feature a new format designed to maximize viewer engagement, with best-of-five matches in the upper bracket and a single-elimination lower bracket for intense elimination drama.', 'Esports', 31500);

-- BladeArena News
INSERT INTO news_articles (template_id, author_id, title, slug, excerpt, content, category, views) VALUES
(2, 3, 'BladeStorm World Championship 2024 Teams Revealed', 'bladestorm-wc-2024-teams', 'Sixteen elite teams from across the globe confirmed for the biggest tournament of the year.', 'The BladeStorm World Championship 2024 has officially confirmed its 16-team roster, featuring powerhouses from North America, Europe, South Korea, China, and Latin America. Defending champions Team Phantom return alongside fierce competitors including Vortex Esports, Dragon Fire Gaming, and fan-favorite underdog squad Thunder Wolves. The championship will be held at the Blade Arena in Seoul, Korea, with $2 million in total prize money on the line. Group stage draws will be announced live on stream this Friday.', 'Esports', 87400),
(2, 1, 'Arena Force Pro Patch 3.8 Overhauls Zone System', 'arena-force-patch-38', 'The controversial zone changes have been reworked based on community feedback and pro player input.', 'Patch 3.8 for Arena Force Pro arrives with a complete overhaul of the zone collapse system, addressing months of community feedback regarding late-game pacing. The new Adaptive Zone system adjusts collapse speed based on the number of players remaining, ensuring tense end-game scenarios without forcing undesirable camping strategies. Additionally, five new weapons join the arsenal, three maps receive significant layout updates, and a new ranked system with visible rating numbers launches alongside the patch.', 'Patch Notes', 52300);

-- MythQuest News
INSERT INTO news_articles (template_id, author_id, title, slug, excerpt, content, category, views) VALUES
(3, 1, 'Legends of Aethoria Unveils "Age of Dragons" Expansion', 'aethoria-age-of-dragons', 'The largest content drop in the game''s history arrives this winter with dragon taming and aerial combat.', 'Legends of Aethoria is set to receive its most ambitious expansion to date: Age of Dragons. This massive content update introduces dragon taming mechanics, aerial combat, a new continent spanning twice the size of the current world, and an entirely new class — the Dragonlord. Players can capture, raise, and customize their own dragons from hatchling to ancient, with each dragon developing unique traits based on how it''s raised. The expansion also includes a new endgame raid against the World Serpent and a player housing system in floating sky islands.', 'Updates', 124500),
(3, 2, 'MythQuest Spring Festival Event Starts Friday', 'mythquest-spring-festival', 'The annual Spring Festival returns with new quests, limited cosmetics, and world boss spawns.', 'The beloved Spring Festival event returns to MythQuest this Friday, bringing with it a host of seasonal activities, limited-time cosmetics, and special world boss encounters. Players can participate in the Bloom Harvest quest chain to earn exclusive floral armor sets, engage in the Faerie Ring mini-game for rare crafting materials, and compete in the Spring Tournament for golden trophy weapons. World boss Thornmantle the Ancient will spawn every six hours across all servers, dropping the coveted Verdant Core crafting material.', 'Events', 36800);

-- PixelVault News
INSERT INTO news_articles (template_id, author_id, title, slug, excerpt, content, category, views) VALUES
(4, 1, 'PixelVault Retro Game Jam Winners Announced', 'pixelvault-game-jam-winners', '150 developers competed to create the best retro game in 72 hours. Here are the incredible results.', 'The PixelVault Retro Game Jam concluded with 150 participating developers from 30 countries, producing an astounding collection of pixel art games across all genres. First place went to indie duo ByteBros for their puzzle-platformer "Magnetic Maze", featuring innovative magnet-based mechanics and 40 hand-crafted levels. Runner-up honors went to solo dev PixelPete for "Star Drifter", a procedurally generated space adventure. All 150 submissions are now playable directly in the PixelVault browser arcade, with the top 10 receiving full commercial release support from the PixelVault publishing label.', 'Community', 24700),
(4, 2, 'RetroBlast Ultimate Reaches 1 Million Players Milestone', 'retroblast-1-million-milestone', 'The retro collection celebrates a massive player count with a free DLC drop and limited edition merch.', 'RetroBlast Ultimate has officially surpassed 1 million active players, a remarkable achievement for a retro-focused game in the modern market. To celebrate, developer PixelForge Studio is releasing a free DLC pack containing 50 additional classic arcade games, 20 new soundtracks by chiptune legends, and a retrospective documentary film about the golden age of arcades. Limited edition physical merchandise including a steel book art collection and enamel pin set is now available on the PixelVault store. The milestone event also unlocks a special in-game trophy for all current players.', 'News', 41200);

-- ============================================================
-- SEED DATA - Gaming Setups
-- ============================================================
INSERT INTO gaming_setups (template_id, user_id, setup_name, owner_name, cpu, gpu, ram, storage, monitor, peripherals, total_cost, description, likes_count, is_featured) VALUES
(1, 2, 'The Neon Machine', 'Alex T.', 'Intel Core i9-14900K', 'NVIDIA RTX 4090 24GB', '64GB DDR5 6000MHz', '4TB NVMe SSD (2x2TB)', 'LG OLED 34" UltraWide 144Hz', 'Corsair K100 Keyboard, Logitech G Pro X Superlight, Audio-Technica ATH-M70x', '$6,800', 'Built for maximum cyberpunk immersion. Every component chosen for peak performance and RGB aesthetics. This rig handles Cyberstrike 2077 at max settings with 120+ FPS.', 2841, 1),
(1, 1, 'Budget Cyber Warrior', 'ByteKing', 'AMD Ryzen 7 7700X', 'AMD Radeon RX 7800 XT 16GB', '32GB DDR5 5200MHz', '2TB NVMe SSD', 'Samsung Odyssey G5 27" 165Hz', 'SteelSeries Apex 7, Razer DeathAdder V3, HyperX Cloud Alpha', '$2,100', 'Proving that you don''t need to spend a fortune for an incredible cyberpunk gaming experience. This build crushes every game at 1440p.', 1205, 0),
(2, 3, 'Pro Esports Station', 'Nova Striker', 'Intel Core i7-14700K', 'NVIDIA RTX 4070 Ti 12GB', '32GB DDR5 5600MHz', '2TB NVMe SSD', 'BenQ ZOWIE XL2546X 24.5" 360Hz', 'Wooting 60HE Keyboard, Finalmouse Ultralight X, beyerdynamic DT 900 Pro X', '$4,200', 'Tournament-grade setup optimized for maximum FPS and minimum input lag. Every peripheral chosen for competitive advantage, not aesthetics.', 3421, 1),
(3, 1, 'The Fantasy Forge', 'LoreKeeper', 'AMD Ryzen 9 7950X', 'NVIDIA RTX 4080 16GB', '64GB DDR5 5200MHz', '6TB NVMe SSD', 'Dell AW3423DW 34" OLED 175Hz', 'Das Keyboard 6 Professional, Logitech MX Master 3S, Sennheiser HD 660S2', '$5,500', 'Crafted for the ultimate fantasy MMO experience. Massive storage for multiple MMOs, a gorgeous OLED display for rich fantasy worlds, and audiophile headphones for immersive soundscapes.', 1876, 1),
(4, 2, 'The Pixel Palace', 'RetroKing88', 'Intel Core i5-13600K', 'NVIDIA RTX 4060 Ti 8GB', '32GB DDR4 3600MHz', '2TB SSD + 4TB HDD', 'ASUS ROG Swift 27" 1440p 165Hz', 'Keychron Q5 Keyboard, Zowie EC2-C Mouse, Retro Handmade Controller Collection', '$2,800', 'A shrine to gaming history. Retro consoles, CRT televisions, and a modern PC capable of running any retro emulator at 4K. The best of both worlds.', 987, 1);

-- ============================================================
-- SEED DATA - Tournaments
-- ============================================================
INSERT INTO tournaments (template_id, name, game_title, prize_pool, max_teams, registered_teams, start_date, end_date, status, description) VALUES
(2, 'BladeStorm World Championship 2024', 'BladeStorm Championship', '$2,000,000', 16, 16, '2024-11-15', '2024-11-24', 'upcoming', 'The biggest BladeStorm tournament of the year. 16 elite teams compete for $2M and the world title.'),
(2, 'Arena Force Pro Regional Cup - NA', 'Arena Force Pro', '$250,000', 32, 28, '2024-10-05', '2024-10-20', 'ongoing', 'North American regional qualifier for the Global Arena Force Championship.'),
(2, 'SiegeWar Elite League Season 3', 'SiegeWar Elite', '$500,000', 24, 24, '2024-09-01', '2024-10-30', 'ongoing', 'Season 3 of the premier SiegeWar competitive league with weekly matches and playoffs.'),
(2, 'Summer BladeStorm Open', 'BladeStorm Championship', '$50,000', 64, 64, '2024-07-10', '2024-08-15', 'completed', 'Open registration tournament for aspiring competitive players. Completed successfully with incredible matches.'),
(1, 'CyberNeon Invitational 2024', 'VoidRunner X', '$150,000', 16, 14, '2024-12-01', '2024-12-08', 'upcoming', 'An elite invitational tournament for the top VoidRunner X players globally.');

-- ============================================================
-- SEED DATA - User Settings
-- ============================================================
INSERT INTO user_settings (user_id, email_notifications, newsletter, dark_mode, language, privacy) VALUES
(1, 1, 1, 1, 'en', 'public'),
(2, 1, 0, 1, 'en', 'public'),
(3, 1, 1, 1, 'en', 'friends');

-- ============================================================
-- END OF SCHEMA
-- ============================================================
