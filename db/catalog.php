<?php declare(strict_types=1);

/**
 * Catálogo curado de itens (atributos de jogo estilo Tarkov). O seed usa isto para popular
 * a tabela `items`. Cada chave é o slug (= nome do arquivo em media/items/<slug>.png).
 *
 * Campos por item:
 *   name, short, cat, sub, slot, w, h, kg, rarity, dur (durabilidade), cols/rows (container),
 *   cal (calibre), mag (capacidade), attach (bocais da arma), price, cur, desc, stack
 *
 * Categorias: weapon,pistol,knife,helmet,armor,rig,backpack,mask,glasses,armband,clothing,
 *             med,food,drink,ammo,mag,grenade,attachment,tool,key,skin,misc
 * Slots de equipamento: helmet,mask,glasses,armor,rig,backpack,primary,secondary,pistol,knife,armband
 */
return [
    // ---- armas ----
    'ak103'      => ['name' => 'AK-103', 'short' => 'AK-103', 'cat' => 'weapon', 'sub' => 'assault_rifle', 'slot' => 'primary', 'w' => 5, 'h' => 2, 'kg' => 3.6, 'dur' => 100, 'cal' => '7.62x39', 'price' => 48500, 'cur' => 'credits', 'desc' => 'Fuzil de assalto russo 7.62x39. Robusto e confiável.',
        'attach' => [['key' => 'sight', 'label' => 'MIRA', 'accepts' => ['sight']], ['key' => 'muzzle', 'label' => 'CANO', 'accepts' => ['suppressor', 'muzzle']], ['key' => 'grip', 'label' => 'EMPUNHADURA', 'accepts' => ['grip']], ['key' => 'mag', 'label' => 'CARREGADOR', 'accepts' => ['mag']]]],
    'm4a1'       => ['name' => 'M4A1', 'short' => 'M4A1', 'cat' => 'weapon', 'sub' => 'assault_rifle', 'slot' => 'primary', 'w' => 5, 'h' => 2, 'kg' => 3.1, 'dur' => 100, 'cal' => '5.56x45', 'price' => 52000, 'cur' => 'credits', 'desc' => 'Carabina modular 5.56x45 NATO. Alta precisão e customização.',
        'attach' => [['key' => 'sight', 'label' => 'MIRA', 'accepts' => ['sight']], ['key' => 'muzzle', 'label' => 'CANO', 'accepts' => ['suppressor', 'muzzle']], ['key' => 'foregrip', 'label' => 'GRIP FRONTAL', 'accepts' => ['grip', 'foregrip']], ['key' => 'tactical', 'label' => 'TÁTICO', 'accepts' => ['tactical', 'flashlight']], ['key' => 'mag', 'label' => 'CARREGADOR', 'accepts' => ['mag']]]],
    'glock17'    => ['name' => 'Glock 17', 'short' => 'G17', 'cat' => 'pistol', 'sub' => 'pistol', 'slot' => 'pistol', 'w' => 2, 'h' => 1, 'kg' => 0.9, 'dur' => 100, 'cal' => '9x19', 'price' => 12800, 'cur' => 'credits', 'desc' => 'Pistola semiautomática 9x19mm. Leve e discreta.',
        'attach' => [['key' => 'sight', 'label' => 'MIRA', 'accepts' => ['sight']], ['key' => 'muzzle', 'label' => 'CANO', 'accepts' => ['suppressor']], ['key' => 'mag', 'label' => 'CARREGADOR', 'accepts' => ['mag']]]],
    'knife-bayonet' => ['name' => 'Baioneta 6X4', 'short' => 'FACA', 'cat' => 'knife', 'sub' => 'melee', 'slot' => 'knife', 'w' => 1, 'h' => 2, 'kg' => 0.3, 'dur' => 100, 'price' => 7400, 'cur' => 'credits', 'desc' => 'Baioneta de combate. Silenciosa e sempre pronta.'],

    // ---- carregadores / munição ----
    'ak-mag'  => ['name' => 'Carregador AK 7.62', 'short' => 'MAG AK', 'cat' => 'mag', 'sub' => 'magazine', 'w' => 1, 'h' => 2, 'kg' => 0.4, 'cal' => '7.62x39', 'mag' => 30, 'price' => 2100, 'cur' => 'credits', 'desc' => 'Carregador curvo de 30 munições 7.62x39.'],
    'm4-mag'  => ['name' => 'Carregador M4 5.56', 'short' => 'MAG M4', 'cat' => 'mag', 'sub' => 'magazine', 'w' => 1, 'h' => 2, 'kg' => 0.3, 'cal' => '5.56x45', 'mag' => 30, 'price' => 1900, 'cur' => 'credits', 'desc' => 'Carregador STANAG de 30 munições 5.56x45.'],
    'glock-mag' => ['name' => 'Carregador Glock 9mm', 'short' => 'MAG G17', 'cat' => 'mag', 'sub' => 'magazine', 'w' => 1, 'h' => 1, 'kg' => 0.15, 'cal' => '9x19', 'mag' => 17, 'price' => 1200, 'cur' => 'credits', 'desc' => 'Carregador reto de 17 munições 9x19.'],
    'ammo-box' => ['name' => 'Caixa de Munição', 'short' => 'MUNIÇÃO', 'cat' => 'ammo', 'sub' => 'ammo_box', 'w' => 2, 'h' => 2, 'kg' => 1.2, 'cal' => '7.62x39', 'stack' => 60, 'price' => 480, 'cur' => 'cash', 'desc' => 'Caixa com 60 munições 7.62x39.'],

    // ---- explosivos ----
    'm67-grenade' => ['name' => 'Granada M67', 'short' => 'M67', 'cat' => 'grenade', 'sub' => 'frag', 'w' => 1, 'h' => 1, 'kg' => 0.4, 'price' => 4900, 'cur' => 'credits', 'desc' => 'Granada de fragmentação. Raio letal de 5m.'],
    'flashbang'   => ['name' => 'Granada de Luz', 'short' => 'FLASH', 'cat' => 'grenade', 'sub' => 'flash', 'w' => 1, 'h' => 1, 'kg' => 0.2, 'stack' => 5, 'price' => 2200, 'cur' => 'credits', 'desc' => 'Granada de atordoamento. Cega e ensurdece.'],

    // ---- equipamento (cabeça/corpo) ----
    'helmet-fast'   => ['name' => 'Capacete FAST MT', 'short' => 'FAST MT', 'cat' => 'helmet', 'sub' => 'helmet', 'slot' => 'helmet', 'w' => 2, 'h' => 2, 'kg' => 1.1, 'dur' => 56, 'price' => 21000, 'cur' => 'credits', 'desc' => 'Capacete balístico classe 4 com trilhos.'],
    'gasmask-gp7'   => ['name' => 'Máscara GP-7', 'short' => 'GP-7', 'cat' => 'mask', 'sub' => 'gasmask', 'slot' => 'mask', 'w' => 2, 'h' => 2, 'kg' => 0.8, 'dur' => 100, 'price' => 9600, 'cur' => 'credits', 'desc' => 'Máscara contra gases com filtro. Protege contra toxinas.'],
    'glasses-aviator' => ['name' => 'Óculos Aviador', 'short' => 'AVIATOR', 'cat' => 'glasses', 'sub' => 'glasses', 'slot' => 'glasses', 'w' => 2, 'h' => 1, 'kg' => 0.1, 'dur' => 30, 'price' => 320, 'cur' => 'points', 'desc' => 'Óculos táticos. Reduz o brilho e protege os olhos.'],
    'vest-83'       => ['name' => 'Colete VEST-83', 'short' => 'VEST-83', 'cat' => 'armor', 'sub' => 'plate_carrier', 'slot' => 'armor', 'w' => 3, 'h' => 3, 'kg' => 5.2, 'dur' => 80, 'price' => 34500, 'cur' => 'credits', 'desc' => 'Colete balístico classe 3 com placas frontais.'],
    'clothing-tactical' => ['name' => 'Traje Caçador', 'short' => 'TÁTICA', 'cat' => 'clothing', 'sub' => 'jacket', 'slot' => 'armor', 'w' => 3, 'h' => 3, 'kg' => 1.4, 'price' => 2400, 'cur' => 'points', 'desc' => 'Uniforme completo camuflado para floresta.'],
    'armband-usec'  => ['name' => 'Braçadeira USEC', 'short' => 'USEC', 'cat' => 'armband', 'sub' => 'armband', 'slot' => 'armband', 'w' => 1, 'h' => 1, 'kg' => 0.05, 'price' => 450, 'cur' => 'points', 'desc' => 'Braçadeira de identificação de facção.'],

    'headset-comtac' => ['name' => 'Fone Tático ComTac', 'short' => 'COMTAC', 'cat' => 'earpiece', 'sub' => 'headset', 'slot' => 'earpiece', 'w' => 2, 'h' => 2, 'kg' => 0.3, 'dur' => 100, 'price' => 8600, 'cur' => 'credits', 'desc' => 'Protetor auricular ativo: amplifica passos e abafa disparos.'],

    // ---- containers ----
    'tactical-rig'  => ['name' => 'Coldre Tático RIG-03', 'short' => 'RIG-03', 'cat' => 'rig', 'sub' => 'chest_rig', 'slot' => 'rig', 'w' => 3, 'h' => 3, 'kg' => 1.1, 'cols' => 5, 'rows' => 2, 'price' => 15600, 'cur' => 'credits', 'desc' => 'Colete de combate com 8 compartimentos de acesso rápido.'],
    'backpack-45l'  => ['name' => 'Mochila Trilha 45L', 'short' => 'TRILHA 45L', 'cat' => 'backpack', 'sub' => 'backpack', 'slot' => 'backpack', 'w' => 3, 'h' => 4, 'kg' => 1.8, 'cols' => 5, 'rows' => 5, 'price' => 18900, 'cur' => 'credits', 'desc' => 'Mochila de campanha com 45L de capacidade.'],
    'weapon-case'   => ['name' => 'Maleta de Arma', 'short' => 'MALETA', 'cat' => 'misc', 'sub' => 'case', 'w' => 4, 'h' => 2, 'kg' => 2.5, 'cols' => 6, 'rows' => 3, 'price' => 900, 'cur' => 'cash', 'desc' => 'Contém uma arma aleatória. Também guarda armas.'],
    'supply-crate'  => ['name' => 'Caixa de Suprimentos', 'short' => 'SUPRIMENTOS', 'cat' => 'misc', 'sub' => 'crate', 'w' => 2, 'h' => 2, 'kg' => 3.0, 'price' => 350, 'cur' => 'cash', 'desc' => 'Contém itens e recursos aleatórios.'],

    // ---- médicos ----
    'medkit'      => ['name' => 'Kit Médico', 'short' => 'KIT MÉD', 'cat' => 'med', 'sub' => 'medkit', 'w' => 1, 'h' => 1, 'kg' => 0.6, 'dur' => 300, 'price' => 3200, 'cur' => 'credits', 'desc' => 'Restaura até 100 de saúde. Reutilizável.'],
    'surgical-kit' => ['name' => 'Kit Cirúrgico', 'short' => 'CIRÚRGICO', 'cat' => 'med', 'sub' => 'surgical', 'w' => 2, 'h' => 1, 'kg' => 0.8, 'dur' => 2, 'price' => 9800, 'cur' => 'credits', 'desc' => 'Restaura membros destruídos. Uso demorado.'],
    'bandage'     => ['name' => 'Bandagem', 'short' => 'BANDAGEM', 'cat' => 'med', 'sub' => 'bandage', 'w' => 1, 'h' => 1, 'kg' => 0.1, 'stack' => 3, 'price' => 850, 'cur' => 'credits', 'desc' => 'Estanca sangramentos. Restaura até 50 de saúde.'],
    'analgesic'   => ['name' => 'Analgésico', 'short' => 'ANALGÉSICO', 'cat' => 'med', 'sub' => 'painkiller', 'w' => 1, 'h' => 1, 'kg' => 0.1, 'stack' => 3, 'price' => 1100, 'cur' => 'credits', 'desc' => 'Remove dor e restaura 25 de saúde.'],
    'xray'        => ['name' => 'Raio-X Portátil', 'short' => 'RAIO-X', 'cat' => 'med', 'sub' => 'diagnostic', 'w' => 2, 'h' => 2, 'kg' => 1.5, 'price' => 2600, 'cur' => 'credits', 'desc' => 'Diagnóstico de fraturas e corpos estranhos.'],
    'blood-test'  => ['name' => 'Hemograma', 'short' => 'HEMOGRAMA', 'cat' => 'med', 'sub' => 'diagnostic', 'w' => 1, 'h' => 1, 'kg' => 0.2, 'stack' => 3, 'price' => 1400, 'cur' => 'credits', 'desc' => 'Detecta infecções e toxinas no sangue.'],
    'clipboard'   => ['name' => 'Prancheta Médica', 'short' => 'PRANCHETA', 'cat' => 'med', 'sub' => 'diagnostic', 'w' => 1, 'h' => 2, 'kg' => 0.3, 'price' => 900, 'cur' => 'credits', 'desc' => 'Registra o estado de saúde do operador.'],

    // ---- comida / bebida ----
    'water-bottle' => ['name' => 'Cantil de Água', 'short' => 'ÁGUA', 'cat' => 'drink', 'sub' => 'water', 'w' => 1, 'h' => 2, 'kg' => 0.6, 'dur' => 60, 'price' => 620, 'cur' => 'credits', 'desc' => 'Restaura hidratação. 60 de capacidade.'],
    'canned-food' => ['name' => 'Ração Enlatada', 'short' => 'LATA', 'cat' => 'food', 'sub' => 'canned', 'w' => 1, 'h' => 1, 'kg' => 0.4, 'stack' => 3, 'price' => 740, 'cur' => 'credits', 'desc' => 'Restaura fome. Não estraga.'],
    'dried-meat'  => ['name' => 'Carne Seca', 'short' => 'CARNE', 'cat' => 'food', 'sub' => 'meat', 'w' => 1, 'h' => 1, 'kg' => 0.2, 'stack' => 3, 'price' => 520, 'cur' => 'credits', 'desc' => 'Restaura fome rapidamente.'],
    'cereal-bar'  => ['name' => 'Barra de Cereal', 'short' => 'CEREAL', 'cat' => 'food', 'sub' => 'snack', 'w' => 1, 'h' => 1, 'kg' => 0.1, 'stack' => 2, 'price' => 280, 'cur' => 'credits', 'desc' => 'Lanche rápido. Restaura pouca fome e energia.'],
    'coffee'      => ['name' => 'Estimulante de XP', 'short' => 'CAFÉ', 'cat' => 'drink', 'sub' => 'stimulant', 'w' => 1, 'h' => 1, 'kg' => 0.2, 'price' => 250, 'cur' => 'points', 'desc' => '+50% de XP por 1 hora.'],
    'jerrycan'    => ['name' => 'Galão de Combustível', 'short' => 'GALÃO', 'cat' => 'misc', 'sub' => 'fuel', 'w' => 2, 'h' => 3, 'kg' => 8.0, 'dur' => 20, 'price' => 1900, 'cur' => 'credits', 'desc' => 'Combustível para veículos e geradores.'],

    // ---- ferramentas / utilidades ----
    'rope'        => ['name' => 'Corda de Rapel', 'short' => 'CORDA', 'cat' => 'tool', 'sub' => 'rope', 'w' => 2, 'h' => 2, 'kg' => 1.2, 'stack' => 5, 'price' => 680, 'cur' => 'credits', 'desc' => 'Permite descer de estruturas altas.'],
    'multitool'   => ['name' => 'Multitool', 'short' => 'MULTITOOL', 'cat' => 'tool', 'sub' => 'tool', 'w' => 1, 'h' => 1, 'kg' => 0.3, 'price' => 1300, 'cur' => 'credits', 'desc' => 'Ferramenta para reparos rápidos em campo.'],
    'tools-wrench' => ['name' => 'Chave Inglesa', 'short' => 'CHAVE', 'cat' => 'tool', 'sub' => 'tool', 'w' => 1, 'h' => 2, 'kg' => 0.7, 'price' => 540, 'cur' => 'credits', 'desc' => 'Abre válvulas e desmonta equipamentos.'],
    'tools-generic' => ['name' => 'Conjunto de Ferramentas', 'short' => 'FERRAMENTAS', 'cat' => 'tool', 'sub' => 'toolkit', 'w' => 2, 'h' => 2, 'kg' => 1.5, 'price' => 2200, 'cur' => 'credits', 'desc' => 'Kit completo de ferramentas de manutenção.'],
    'radio'       => ['name' => 'Rádio de Campo', 'short' => 'RÁDIO', 'cat' => 'tool', 'sub' => 'radio', 'w' => 1, 'h' => 2, 'kg' => 0.9, 'dur' => 100, 'price' => 11200, 'cur' => 'credits', 'desc' => 'Comunicação de longo alcance. Chama extração.'],
    'compass'     => ['name' => 'Bússola', 'short' => 'BÚSSOLA', 'cat' => 'tool', 'sub' => 'compass', 'w' => 1, 'h' => 1, 'kg' => 0.15, 'price' => 480, 'cur' => 'credits', 'desc' => 'Orientação no terreno. Mostra o norte.'],
    'sleeping-bag' => ['name' => 'Saco de Dormir', 'short' => 'SACO', 'cat' => 'misc', 'sub' => 'bedroll', 'w' => 3, 'h' => 2, 'kg' => 1.6, 'price' => 1200, 'cur' => 'credits', 'desc' => 'Ponto de retorno seguro. Recupera energia.'],
    'keycard'     => ['name' => 'Cartão de Acesso', 'short' => 'CARTÃO', 'cat' => 'key', 'sub' => 'keycard', 'w' => 1, 'h' => 1, 'kg' => 0.02, 'price' => 1500, 'cur' => 'cash', 'desc' => 'Abre portas de segurança em bases militares.'],
    'skin-camo'   => ['name' => 'Camuflagem Mata Fechada', 'short' => 'CAMO', 'cat' => 'skin', 'sub' => 'weapon_skin', 'w' => 1, 'h' => 1, 'kg' => 0.1, 'price' => 1200, 'cur' => 'points', 'desc' => 'Skin de arma camuflada. Apenas cosmético.'],
];
