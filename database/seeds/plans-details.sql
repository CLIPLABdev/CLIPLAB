-- Descrições e créditos diários dos planos. Só preenche o que ainda estiver vazio,
-- preservando alterações feitas pela administração.
UPDATE plans SET description = 'Para testar o laboratório. 10 créditos por dia (1 crédito = 1 minuto de vídeo), cortes automáticos com IA, legendas e exportação em MP4.' WHERE slug = 'free' AND description = '';
UPDATE plans SET daily_credits = 10 WHERE slug = 'free' AND daily_credits = 0;
UPDATE plans SET description = 'Para quem publica toda semana. 25 créditos por dia, envios de até 1 GB e 10 GB para guardar seus cortes.' WHERE slug = 'iniciante' AND description = '';
UPDATE plans SET daily_credits = 25 WHERE slug = 'iniciante' AND daily_credits = 0;
UPDATE plans SET description = 'Para criadores intensos. 50 créditos por dia e envios de até 3 GB, o bastante para vídeos longos de até 2 horas.' WHERE slug = 'louco' AND description = '';
UPDATE plans SET daily_credits = 50 WHERE slug = 'louco' AND daily_credits = 0;
UPDATE plans SET description = 'Para equipes e agências. 100 créditos por dia e 100 GB de armazenamento para produzir em volume.' WHERE slug = 'experiente' AND description = '';
UPDATE plans SET daily_credits = 100 WHERE slug = 'experiente' AND daily_credits = 0;
