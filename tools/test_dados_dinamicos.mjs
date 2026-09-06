/**
 * Guarda contra telas que mostram dado INVENTADO em vez do que está no banco.
 *
 * A tela INÍCIO exibia passe nível 45 / 2450 XP e um contrato "CAÇA NA FLORESTA 3/8" fixos
 * em app/Data/inicio.php, mesmo para um jogador nível 0. Este teste compara o que a página
 * renderiza com o que o MySQL guarda para o mesmo user_id.
 *
 *   node tools/test_dados_dinamicos.mjs
 * Sai com código 2 se qualquer caso falhar.
 */
import puppeteer from 'puppeteer-core';
import { existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
const PHP = 'C:/wamp64/bin/php/php8.3.28/php.exe';
const BASE = 'http://localhost/extracthunters';
let falhas = 0;
const check = (nome, ok, detalhe = '') => {
  if (!ok) falhas++;
  console.log(`${ok ? 'OK  ' : 'FALHA'} ${nome}${detalhe ? ' — ' + detalhe : ''}`);
};

/** Lê a verdade direto do banco, sem passar pela aplicação. */
const doBanco = JSON.parse(execFileSync(PHP, ['-r', `
  require "app/bootstrap.php";
  use App\\Core\\Database;
  $uid = 1;
  $s  = Database::one("SELECT * FROM seasons WHERE is_current=1");
  $ps = Database::one("SELECT * FROM player_season WHERE user_id=:u AND season_id=:s", ["u"=>$uid,"s"=>$s["id"]]);
  $m  = Database::one("SELECT m.name, m.target, pm.progress FROM missions m
                       JOIN player_missions pm ON pm.mission_id=m.id AND pm.user_id=:u
                       WHERE pm.status IN ('active','available')
                       ORDER BY (pm.status <> 'active'), m.sort_order LIMIT 1", ["u"=>$uid]);
  echo json_encode([
    "passLevel" => (int)$ps["pass_level"],
    "passXp"    => (int)$ps["pass_xp"],
    "xpNext"    => (int)$s["xp_per_level"],
    "tag"       => (string)$s["tag"],
    "missao"    => (string)$m["name"],
    "progresso" => (int)$m["progress"],
    "alvo"      => (int)$m["target"],
  ], JSON_UNESCAPED_UNICODE);
`], { cwd: 'C:/wamp64/www/extracthunters', encoding: 'utf8' }).trim());

const browser = await puppeteer.launch({
  executablePath: CHROME, headless: true,
  args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
  defaultViewport: { width: 1672, height: 941 },
});
const page = await browser.newPage();
await page.goto(`${BASE}/inicio`, { waitUntil: 'networkidle2' });
await new Promise((r) => setTimeout(r, 1200));

const tela = await page.evaluate(() => {
  const txt = (s) => document.querySelector(s)?.textContent.trim() ?? '';
  return {
    nivel:     txt('.season__hex'),
    sub:       txt('.season__sub'),
    xp:        txt('.season__xp'),
    relogio:   document.querySelector('.season__clock')?.dataset.countdown ?? '',
    missao:    txt('.contract__name'),
    contagem:  txt('.contract__count'),
    premios:   [...document.querySelectorAll('.contract__reward-val')].map((e) => e.dataset.count),
  };
});

// só dígitos: a tela formata com separador de milhar (7.400) e o banco guarda 7400
const nums = (s) => (s.match(/\d+/g) || []).map(Number);

check('passe: nível da tela = player_season.pass_level',
  nums(tela.nivel)[0] === doBanco.passLevel, `tela=${tela.nivel} banco=${doBanco.passLevel}`);

const xpTela = nums(tela.xp.replace(/\./g, ''));
check('passe: XP da tela = player_season.pass_xp / seasons.xp_per_level',
  xpTela[0] === doBanco.passXp && xpTela[1] === doBanco.xpNext,
  `tela=${tela.xp.replace(/\s+/g, ' ')} banco=${doBanco.passXp}/${doBanco.xpNext}`);

check('passe: rótulo da temporada = seasons.tag',
  tela.sub === doBanco.tag, `tela="${tela.sub}" banco="${doBanco.tag}"`);

check('passe: relógio aponta para uma data real',
  !Number.isNaN(Date.parse(tela.relogio)) && Date.parse(tela.relogio) > Date.now(),
  tela.relogio || '(vazio)');

check('contrato: missão da tela = missão ativa do jogador',
  tela.missao === doBanco.missao, `tela="${tela.missao}" banco="${doBanco.missao}"`);

const cont = nums(tela.contagem);
check('contrato: progresso da tela = player_missions.progress / missions.target',
  cont[0] === doBanco.progresso && cont[1] === doBanco.alvo,
  `tela=${tela.contagem} banco=${doBanco.progresso}/${doBanco.alvo}`);

check('contrato: recompensas vieram do banco (não vazias)',
  tela.premios.length > 0 && tela.premios.every((v) => Number(v) > 0), tela.premios.join(', '));

await browser.close();
console.log(falhas === 0 ? '\nDados dinâmicos: todos os casos passaram.' : `\n${falhas} falha(s).`);
process.exit(falhas ? 2 : 0);
