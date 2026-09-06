<?php declare(strict_types=1);

/**
 * Catálogo da tela HABILIDADES (tabela `skills`).
 *
 * Roda sozinho (`php db/seed_skills.php`) ou dentro de `db/seed.php`. É idempotente:
 * cria as colunas que faltarem e faz UPSERT de cada nó a partir de `app/Data/habilidades.php`,
 * SEM tocar em `player_skills` (o progresso do jogador é preservado).
 *
 * Colunas derivadas do catálogo (é daqui que o painel de detalhe tira o texto do PRÓXIMO NÍVEL):
 *   step   — magnitude POR NÍVEL (ex.: 12 em "reduz o recuo em 12% por nível")
 *   unit   — sufixo da magnitude ('%', ' segundos', ' metros', '' …)
 *   effect — frase do efeito com o marcador {v} no lugar do valor
 *   image  — arte do painel de detalhe, relativa a /media (servida por media())
 */

if (!function_exists('seed_skills_catalog')) {

/** Arte do painel de detalhe por categoria (arquivos reais em /media). */
function skills_category_image(string $cat): string
{
    return [
        'combate'       => 'ui/skill-preview.jpg',
        'sobrevivencia' => 'regions/region-floresta-verde.jpg',
        'mobilidade'    => 'maps/vale-do-eco.jpg',
        'suporte'       => 'missions/mission-suprimentos-medicos.jpg',
        'equipamento'   => 'missions/mission-tecnologia-recuperada.jpg',
        'comercio'      => 'maps/porto-industrial.jpg',
    ][$cat] ?? 'ui/skill-preview.jpg';
}

/**
 * Quebra a descrição em (magnitude por nível, unidade, frase com {v}).
 * Quando não há número aproveitável devolve step = null e effect = null — nesse caso
 * o controller cai para "Desbloqueia: <descrição>", que continua sendo texto do próprio nó.
 * @return array{0: float|null, 1: string, 2: string|null}
 */
function skills_effect_parts(string $desc, int $maxLevel): array
{
    $clean = trim(preg_replace('/\s+por n[íi]vel/u', '', $desc) ?? $desc);
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(%|segundos?|minutos?|horas?|metros?)?/u', $clean, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return [null, '', null];
    }
    $num  = (float)str_replace(',', '.', $m[1][0]);
    $word = isset($m[2]) && $m[2][0] !== '' ? (string)$m[2][0] : '';
    $unit = $word === '%' ? '%' : ($word !== '' ? ' ' . $word : '');

    // Sem unidade explícita e com vários níveis o número acompanha um substantivo
    // ("1 espaço de carregador") — trocar só o algarismo geraria concordância errada.
    if ($unit === '' && $maxLevel > 1) {
        return [null, '', null];
    }
    $start  = (int)$m[0][1];
    $length = strlen($m[0][0]);
    $effect = substr($clean, 0, $start) . '{v}' . substr($clean, $start + $length);
    return [$num, $unit, trim($effect)];
}

function seed_skills_catalog(): int
{
    $db = \App\Core\Database::class;

    $cols = array_column($db::all('SHOW COLUMNS FROM skills'), 'Field');
    foreach ([
        'image'  => "ADD COLUMN image VARCHAR(160) NULL AFTER description",
        'step'   => "ADD COLUMN step DECIMAL(7,2) NULL AFTER image",
        'unit'   => "ADD COLUMN unit VARCHAR(16) NULL AFTER step",
        'effect' => "ADD COLUMN effect VARCHAR(220) NULL AFTER unit",
    ] as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            $db::run('ALTER TABLE skills ' . $sql);
        }
    }

    $data = require dirname(__DIR__) . '/app/Data/habilidades.php';
    $n = 0;
    foreach (($data['trees'] ?? []) as $cat => $rows) {
        foreach ($rows as $ti => $row) {
            foreach ($row as $ci => $node) {
                if (empty($node['slug'])) {
                    continue;
                }
                $max  = max(1, (int)($node['max'] ?? 1));
                $desc = (string)($node['desc'] ?? '');
                if (isset($node['effect'])) {          // efeito escrito à mão no catálogo
                    $step   = (float)($node['step'] ?? 1);
                    $unit   = (string)($node['unit'] ?? '');
                    $effect = (string)$node['effect'];
                } else {
                    [$step, $unit, $effect] = skills_effect_parts($desc, $max);
                }
                $db::run(
                    'INSERT INTO skills (slug, category, tier, col, name, icon, max_level, cost, description, image, step, unit, effect)
                     VALUES (:slug,:cat,:tier,:col,:name,:icon,:max,:cost,:desc,:img,:step,:unit,:eff)
                     ON DUPLICATE KEY UPDATE category=VALUES(category), tier=VALUES(tier), col=VALUES(col),
                       name=VALUES(name), icon=VALUES(icon), max_level=VALUES(max_level), cost=VALUES(cost),
                       description=VALUES(description), image=VALUES(image), step=VALUES(step),
                       unit=VALUES(unit), effect=VALUES(effect)',
                    [
                        'slug' => (string)$node['slug'], 'cat' => (string)$cat, 'tier' => $ti + 1, 'col' => $ci + 1,
                        'name' => (string)($node['name'] ?? 'Habilidade'), 'icon' => (string)($node['icon'] ?? 'star'),
                        'max' => $max, 'cost' => (int)($node['cost'] ?? 0), 'desc' => $desc,
                        'img' => skills_category_image((string)$cat),
                        'step' => $step, 'unit' => $unit, 'eff' => $effect,
                    ]
                );
                $n++;
            }
        }
    }
    return $n;
}

}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    require dirname(__DIR__) . '/app/bootstrap.php';
    echo seed_skills_catalog() . " habilidades atualizadas no catálogo.\n";
}
