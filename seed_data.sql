-- Too Many Coins - Seed Data

-- Cosmetic catalog items (using canonical price tiers: 25, 80, 250, 800, 2400)
INSERT INTO cosmetic_catalog (name, description, category, price_global_stars, css_class) VALUES
-- Avatar Frames (25, 80, 250, 800, 2400)
('Bronze Ring', 'A simple bronze frame around your avatar.', 'avatar_frame', 25, 'frame-bronze'),
('Silver Ring', 'A polished silver frame.', 'avatar_frame', 80, 'frame-silver'),
('Gold Ring', 'A gleaming gold frame.', 'avatar_frame', 250, 'frame-gold'),
('Diamond Ring', 'A sparkling diamond-encrusted frame.', 'avatar_frame', 800, 'frame-diamond'),
('Celestial Ring', 'An ethereal cosmic frame with animated stars.', 'avatar_frame', 2400, 'frame-celestial'),

-- Name Colors (25, 80, 250, 800, 2400)
('Ember', 'A warm orange-red name color.', 'name_color', 25, 'name-ember'),
('Ocean', 'A deep blue name color.', 'name_color', 80, 'name-ocean'),
('Verdant', 'A rich green name color.', 'name_color', 250, 'name-verdant'),
('Royal Purple', 'A majestic purple name color.', 'name_color', 800, 'name-royal'),
('Prismatic', 'A shifting rainbow name color.', 'name_color', 2400, 'name-prismatic'),

-- Profile Backgrounds (25, 80, 250, 800, 2400)
('Parchment', 'A subtle aged paper texture.', 'profile_bg', 25, 'bg-parchment'),
('Midnight', 'A dark starry background.', 'profile_bg', 80, 'bg-midnight'),
('Aurora', 'Northern lights shimmer behind your profile.', 'profile_bg', 250, 'bg-aurora'),
('Volcanic', 'Molten lava flows in the background.', 'profile_bg', 800, 'bg-volcanic'),
('Void', 'An otherworldly cosmic void.', 'profile_bg', 2400, 'bg-void'),

-- Titles (25, 80, 250, 800, 2400)
('Newcomer', 'A humble beginning.', 'title', 25, 'title-newcomer'),
('Trader', 'Known for making deals.', 'title', 80, 'title-trader'),
('Strategist', 'A calculated mind.', 'title', 250, 'title-strategist'),
('Magnate', 'A titan of the economy.', 'title', 800, 'title-magnate'),
('Legend', 'A name spoken in whispers.', 'title', 2400, 'title-legend'),

-- Effects (80, 250, 800, 2400)
('Sparkle', 'Subtle sparkle particles on your profile.', 'effect', 80, 'effect-sparkle'),
('Flame', 'Gentle flame wisps around your name.', 'effect', 250, 'effect-flame'),
('Lightning', 'Crackling energy surrounds your profile.', 'effect', 800, 'effect-lightning'),
('Supernova', 'An explosive cosmic effect.', 'effect', 2400, 'effect-supernova');
