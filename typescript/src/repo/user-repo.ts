/**
 * Contas de usuário e criação do jogador associado — o App\Repo\UserRepo do PHP.
 * Um jogador novo nasce nível 0 / 0 XP, com dinheiro para a loja e um kit mínimo no stash.
 */
import { ctx } from '../core/context';
import * as DB from '../core/database';
import * as Password from '../core/password';
import type { Dict } from '../core/view';
import { STASH_COLS } from './inventory-repo';

/** Usuário por username OU e-mail (sem diferença de maiúsculas: COLLATE NOCASE na migration). */
export async function findByLogin(login: string): Promise<Dict | null> {
  const l = login.trim();
  if (l === '') return null;
  return DB.one('SELECT * FROM users WHERE username = :u OR email = :u LIMIT 1', { u: l });
}

export async function usernameTaken(username: string): Promise<boolean> {
  return Number(await DB.value('SELECT COUNT(*) FROM users WHERE username = :u', { u: username })) > 0;
}

export async function emailTaken(email: string): Promise<boolean> {
  return Number(await DB.value('SELECT COUNT(*) FROM users WHERE email = :e', { e: email })) > 0;
}

/** Confere a senha e, se o formato do hash for antigo para o algoritmo configurado, regrava. */
export async function verify(user: Dict, senha: string): Promise<boolean> {
  const armazenado = String(user.password_hash ?? '');
  if (!(await Password.verify(senha, armazenado))) return false;
  const algo = ctx().env.PASSWORD_ALGO;
  if (Password.needsRehash(armazenado, algo)) {
    const novo = await Password.hash(senha, algo);
    await DB.run('UPDATE users SET password_hash = :h WHERE id = :id', { h: novo, id: Number(user.id) });
  }
  return true;
}

const KIT_INICIAL = ['glock17', 'glock-mag', 'knife-bayonet', 'ak-mag', 'medkit', 'bandage', 'water-bottle'];

/** Cria usuário + jogador + progresso zerado + kit inicial. Devolve o id do usuário. */
export async function create(username: string, email: string, senha: string): Promise<number> {
  const hash = await Password.hash(senha, ctx().env.PASSWORD_ALGO);
  const uid = await DB.insert('users', { username, email, password_hash: hash });

  try {
    const health = {
      saude: 100, energia: 100, hidratacao: 100, fome: 100, temperatura: 36.6,
      limbs: { cabeca: 35, torax: 85, estomago: 70, braco_esq: 60, braco_dir: 60, perna_esq: 65, perna_dir: 65 },
      limbs_max: { cabeca: 35, torax: 85, estomago: 70, braco_esq: 60, braco_dir: 60, perna_esq: 65, perna_dir: 65 },
    };
    const stmts: D1PreparedStatement[] = [
      DB.insertStmt('players', {
        user_id: uid,
        display_name: username,
        level: 0,
        xp: 0,
        xp_next: 1000,
        credits: 150000, // dinheiro inicial para a loja
        cash: 2000,
        points: 1000,
        clan: 'Resistência',
        class: 'ASSALTO',
        class_icon: 'class-assault',
        avatar: 'portraits/op-assault.jpg',
        player_rank: 'RECRUTA',
        season_level: 0,
        skill_points: 0,
        health,
        survival: { weight_max: 60 },
      }),
      // progresso zerado de missões/conquistas (para conciliar por id desde o começo)
      DB.prepare(
        `INSERT INTO player_missions (user_id, mission_id, status, progress)
         SELECT :u, id, CASE WHEN locked THEN 'bloqueada' ELSE 'disponivel' END, 0 FROM missions`,
        { u: uid },
      ),
      DB.prepare(
        'INSERT INTO player_achievements (user_id, achievement_id, progress) SELECT :u, id, 0 FROM achievements',
        { u: uid },
      ),
    ];

    // kit inicial no stash (a Glock vem com carregador, senão não dá para atirar)
    const itens = await DB.all(
      `SELECT id, slug, grid_w, grid_h, durability_max, mag_capacity FROM items
        WHERE slug IN (${KIT_INICIAL.map((_, i) => ':s' + i).join(',')})`,
      Object.fromEntries(KIT_INICIAL.map((s, i) => ['s' + i, s])),
    );
    const porSlug = new Map(itens.map((i) => [String(i.slug), i]));
    let x = 0;
    let y = 0;
    for (const slug of KIT_INICIAL) {
      const item = porSlug.get(slug);
      if (!item) continue;
      stmts.push(
        DB.insertStmt('player_items', {
          user_id: uid,
          item_id: Number(item.id),
          container: 'stash',
          pos_x: x,
          pos_y: y,
          quantity: 1,
          durability_cur: item.durability_max !== null ? Number(item.durability_max) : null,
          ammo_cur: Number(item.mag_capacity ?? 0) || null,
          acquired_via: 'inicial',
        }),
      );
      x += Number(item.grid_w);
      if (x >= STASH_COLS - 1) {
        x = 0;
        y += 2;
      }
    }
    await DB.batch(stmts);
  } catch (e) {
    // O D1 não abre transação interativa: se o resto falhar, desfaz a conta à mão para não
    // sobrar usuário sem jogador (o PHP fazia isso com rollBack()).
    await DB.run('DELETE FROM users WHERE id = :id', { id: uid });
    throw e;
  }
  return uid;
}
