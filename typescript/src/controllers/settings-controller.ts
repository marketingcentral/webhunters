/**
 * Tela CONFIGURAÇÕES — o App\Controllers\SettingsController do PHP.
 *
 * Catálogo (quais opções existem, tipos e padrões) vem de src/data/configuracoes.json — igual
 * para todos. O VALOR de cada opção é por jogador e vive em `player_settings`, conciliado por
 * user_id (player-settings-repo). Cada aba lê o catálogo, sobrepõe o que o jogador gravou e
 * devolve à view.
 */
import { ctx } from '../core/context';
import * as DB from '../core/database';
import { date } from '../core/fuso';
import { dataMock, flash, num } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as SettingsRepo from '../repo/player-settings-repo';
import { configuracoes } from '../views/pages/configuracoes';
import { Controller } from './controller';

/** Chave da predefinição (ladrilhos do painel direito) por aba. */
const PRESET = 'set_preset_';

/** Campos da aba CONTA que pertencem à tabela `users`, não a `player_settings`. */
const ACCOUNT = ['username', 'email'];

type Account = { username: string; email: string; last_login: string } | Record<string, never>;

/** filter_var(FILTER_VALIDATE_EMAIL), aproximado (mesma regra do auth-controller). */
function emailValido(e: string): boolean {
  return /^[^\s@"(),:;<>[\\\]]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/.test(e);
}

/** Verdade no sentido do PHP: "", "0", 0, false, null e [] são falsos. */
function phpTrue(v: unknown): boolean {
  return !(v === undefined || v === null || v === false || v === '' || v === '0' || v === 0 || (Array.isArray(v) && v.length === 0));
}

const mbLen = (s: string): number => Array.from(s).length;
const mbSub = (s: string, n: number): string => Array.from(s).slice(0, n).join('');

export class SettingsController extends Controller {
  async index(req: AppRequest, params: Params): Promise<AppResponse> {
    const catalog = dataMock('configuracoes');
    const slugs = ((catalog.tabs ?? []) as Dict[]).map((t) => String(t.slug));
    const aba = String(this.pick(params.aba, slugs, slugs[0] ?? 'jogabilidade'));
    const uid = this.userId();

    if (req.isPost()) return this.handlePost(req, catalog, aba, uid);

    const saved = await SettingsRepo.forUser(uid);
    const groups = await this.mergeGroups(catalog.groups?.[aba] ?? [], saved, aba, uid);
    const values = this.flatten(groups);
    const detail = await this.detail(catalog, aba, saved, values, uid);

    const data = {
      title: catalog.title ?? 'CONFIGURAÇÕES',
      tabs: catalog.tabs ?? [],
      active_tab: aba,
      tab: this.tab(catalog.tabs ?? [], aba),
      groups,
      detail,
    };
    const page = this.page('configuracoes', 'Configurações', {
      bg: 'bg/settings.jpg',
      back: { label: 'SAIR', href: null },
    });
    return this.render(configuracoes, page, data);
  }

  /* ===================================================================== POST */

  private async handlePost(req: AppRequest, catalog: Dict, aba: string, uid: number): Promise<AppResponse> {
    this.csrf(req);
    // Índice já com os valores atuais do jogador: entrada inválida cai para o que ele tem
    // hoje, nunca para o padrão do catálogo (que sobrescreveria dados reais em silêncio).
    const items = this.items(await this.mergeGroups(catalog.groups?.[aba] ?? [], await SettingsRepo.forUser(uid), aba, uid));
    const action = req.input('action', 'apply') ?? 'apply';

    if (action === 'reset') {
      // username/e-mail vivem em `users` (identidade da conta) e não voltam "ao padrão".
      const keys = [...Object.keys(items).filter((k) => !ACCOUNT.includes(k)), PRESET + aba];
      const n = await SettingsRepo.reset(uid, keys);
      flash(
        'ok',
        n > 0
          ? `Padrões restaurados (${n} ${n === 1 ? 'opção voltou' : 'opções voltaram'} ao valor de fábrica).`
          : 'Nenhuma alteração para restaurar: a aba já está nos padrões.',
      );
      return this.redirect('configuracoes/' + aba);
    }

    const values: Record<string, string> = {};
    for (const [key, item] of Object.entries(items)) values[key] = this.sanitize(item, req.input(key));
    const preset = this.presetSlug(catalog, aba, req.input('set_preset', '') ?? '');
    if (preset !== null) values[PRESET + aba] = preset;

    let warn: string | null = null;
    if (aba === 'conta') {
      warn = await this.saveAccount(uid, { username: req.input('username', '') ?? '', email: req.input('email', '') ?? '' });
      for (const k of ACCOUNT) delete values[k];
    }

    const n = await SettingsRepo.save(uid, values);
    if (warn !== null) flash('error', warn);
    flash(
      n > 0 ? 'ok' : 'error',
      n > 0
        ? `Alterações aplicadas (${n} ${n === 1 ? 'opção salva' : 'opções salvas'}).`
        : 'Não foi possível salvar: banco de dados indisponível.',
    );
    return this.redirect('configuracoes/' + aba);
  }

  /* ============================================================== catálogo */

  /** Indexa os itens da aba por chave. */
  private items(groups: Dict[]): Record<string, Dict> {
    const out: Record<string, Dict> = {};
    for (const g of groups) for (const it of (g.items ?? []) as Dict[]) out[String(it.key)] = it;
    return out;
  }

  /** Catálogo da aba + valores gravados do jogador (e, na aba conta, os dados reais do usuário). */
  private async mergeGroups(groups: Dict[], saved: Record<string, string>, aba: string, uid: number): Promise<Dict[]> {
    const account: Dict = aba === 'conta' ? await this.account(uid) : {};
    return groups.map((group) => ({
      ...group,
      items: ((group.items ?? []) as Dict[]).map((item0) => {
        const item: Dict = { ...item0 };
        const key = String(item.key);
        const type = String(item.type);
        if (account[key] !== undefined && account[key] !== null && account[key] !== '') item.value = account[key];
        if (key in saved && !(aba === 'conta' && ACCOUNT.includes(key))) {
          item.value = this.restore(item, saved[key]!);
          item.saved = true;
        }
        if (type === 'range') item.max = this.rangeMax(item);
        if (type === 'stepper' || type === 'select') item.value = String(item.value);
        return item;
      }),
    }));
  }

  /** Valores planos da aba (chave => valor já resolvido), usados pelos stats do painel direito. */
  private flatten(groups: Dict[]): Dict {
    const out: Dict = {};
    for (const g of groups) for (const it of (g.items ?? []) as Dict[]) out[String(it.key)] = it.value ?? null;
    return out;
  }

  private tab(tabs: Dict[], aba: string): Dict | null {
    return tabs.find((t) => t.slug === aba) ?? null;
  }

  private rangeMax(item: Dict): number {
    return String(item.unit ?? '%') === '' ? 120 : 100;
  }

  /* ============================================================ saneamento */

  /** Valor gravado -> valor tipado para a view. */
  private restore(item: Dict, stored: string): unknown {
    switch (String(item.type)) {
      case 'toggle':
        return stored === '1';
      case 'range':
        return Math.max(0, Math.min(this.rangeMax(item), Math.round(parseFloat(stored) || 0)));
      case 'stepper':
      case 'select':
        return ((item.options ?? []) as unknown[]).map(String).includes(stored) ? stored : String(item.value);
      default:
        return stored;
    }
  }

  /** Entrada do POST -> string segura para gravar. */
  private sanitize(item: Dict, raw: string | null): string {
    const type = String(item.type);
    const str = raw === null ? '' : raw.trim();

    if (type === 'toggle') {
      // checkbox desmarcado não é enviado pelo navegador: ausência = desligado.
      return str === '1' || str === 'on' || str === 'true' ? '1' : '0';
    }
    if (type === 'range') {
      const max = this.rangeMax(item);
      const numerico = str !== '' && Number.isFinite(Number(str));
      const n = numerico ? Math.round(Number(str)) : Math.round(Number(item.value) || 0);
      return String(Math.max(0, Math.min(max, n)));
    }
    if (type === 'stepper' || type === 'select') {
      const opts = ((item.options ?? []) as unknown[]).map(String);
      return opts.includes(str) ? str : String(item.value);
    }
    if (type === 'key') {
      const up = str.toUpperCase();
      return /^[\p{L}\p{N}][\p{L}\p{N} +\-/.]{0,11}$/u.test(up) ? up : String(item.value);
    }
    if (String(item.key) === 'email') {
      return emailValido(str) ? mbSub(str, 160) : String(item.value);
    }
    const clean = str.replace(/[^\p{L}\p{N}_\-. @]/gu, '');
    return clean !== '' ? mbSub(clean, 40) : String(item.value);
  }

  /** Slug de predefinição válido para a aba (ou null quando não veio nada usável). */
  private presetSlug(catalog: Dict, aba: string, raw: string): string | null {
    for (const opt of this.presetOptions(catalog, aba)) {
      if (String(opt.slug ?? '') === raw && raw !== '') return raw;
    }
    return null;
  }

  private presetOptions(catalog: Dict, aba: string): Dict[] {
    const detail: Dict = catalog.details?.[aba] ?? (aba === 'jogabilidade' ? (catalog.difficulty ?? {}) : {});
    return (detail.options ?? []) as Dict[];
  }

  /* ======================================================= painel de detalhe */

  /** Painel direito: marca a predefinição do jogador e resolve os stats a partir dos valores reais. */
  private async detail(catalog: Dict, aba: string, saved: Record<string, string>, values: Dict, uid: number): Promise<Dict> {
    const detail: Dict = { ...(catalog.details?.[aba] ?? (aba === 'jogabilidade' ? (catalog.difficulty ?? {}) : {})) };
    if (Object.keys(detail).length === 0) return {};
    const options = ((detail.options ?? []) as Dict[]).map((o) => ({ ...o }));
    let chosen: string | null = saved[PRESET + aba] ?? null;

    // Na jogabilidade a predefinição É a dificuldade: o stepper manda quando não há escolha gravada.
    if (aba === 'jogabilidade' && values.dificuldade !== undefined && values.dificuldade !== null) {
      for (const opt of options) {
        if (String(opt.label).toUpperCase() === String(values.dificuldade).toUpperCase()) {
          chosen = String(opt.slug);
          break;
        }
      }
    }
    let found = false;
    for (const opt of options) {
      const on = chosen !== null ? String(opt.slug) === chosen : Boolean(opt.active);
      opt.active = on;
      if (on) {
        found = true;
        detail.current = String(opt.label);
        detail.note = String(opt.desc ?? '');
      }
    }
    if (!found && options.length > 0) {
      options[0]!.active = true;
      detail.current = String(options[0]!.label);
      detail.note = String(options[0]!.desc ?? '');
    }
    detail.options = options;
    detail.stats = await this.stats(catalog, (detail.stats ?? []) as Dict[], values, uid);
    return detail;
  }

  /**
   * Resolve cada stat: `src` aponta para a chave da opção (ou @campo do jogador) e `fmt` diz
   * como formatar. Sem `src`, o valor estático do catálogo é mantido.
   */
  private async stats(catalog: Dict, stats0: Dict[], values: Dict, uid: number): Promise<Dict[]> {
    const levels: Dict = catalog.difficulty_levels ?? {};
    const account: Dict = await this.account(uid);
    const binds = Object.keys(values)
      .filter((k) => k.startsWith('k_'))
      .map((k) => String(values[k]).toUpperCase());
    const dupes = binds.length - new Set(binds).size;
    const playerName = String((await this.player()).name ?? '—');

    return stats0.map((st0) => {
      const st: Dict = { ...st0 };
      const src = String(st.src ?? '');
      const fmt = String(st.fmt ?? 'raw');
      if (src === '') return st;
      let val: string | null = null;
      if (src === '@keybinds') val = String(binds.length);
      else if (src === '@conflicts') val = dupes > 0 ? dupes + (dupes === 1 ? ' tecla' : ' teclas') : 'Nenhum';
      else if (src === '@player') val = playerName;
      else if (src === '@email') val = String(account.email ?? '') !== '' ? 'Sim' : 'Não';
      else if (src === '@last_login') val = account.last_login ?? 'Primeiro acesso';
      else if (src.startsWith('@level:')) {
        const lvl = String(values[src.slice(7)] ?? '').toUpperCase();
        val = String(levels[lvl]?.[String(st.field ?? '')] ?? st.value ?? '—');
      } else if (src in values) {
        const raw = values[src];
        if (fmt === 'pct') val = num(parseFloat(String(raw)) || 0) + '%';
        else if (fmt === 'onoff') val = phpTrue(raw) ? String(st.on ?? 'Ativo') : String(st.off ?? 'Inativo');
        else if (fmt === 'title') val = this.titleCase(String(raw));
        else val = typeof raw === 'boolean' ? (raw ? '1' : '') : String(raw ?? '');
      }
      if (val !== null && val !== '') st.value = val;
      delete st.src;
      delete st.fmt;
      delete st.field;
      delete st.on;
      delete st.off;
      return st;
    });
  }

  /** "PADRÃO DO SISTEMA" -> "Padrão do sistema" (preserva siglas conhecidas como USB/TAA/HZ). */
  private titleCase(v: string): string {
    const small = ['DO', 'DA', 'DE', 'E', 'EM', 'A', 'O'];
    const acro = ['USB', 'HUD', 'ADS', 'FPS', 'GPU', 'CPU', 'HDR', 'TAA', 'FXAA', 'DLSS', 'HZ', 'PC', 'TV', 'VR', '2K', '4K', 'ID', 'IP', 'AMD', 'RGB'];
    const out: string[] = [];
    v.trim().split(/\s+/u).forEach((w, i) => {
      if (w === '') return;
      const bare = w.replace(/^[()]+|[()]+$/g, '');
      if (acro.includes(bare.toUpperCase()) || /^\p{Nd}/u.test(bare)) {
        out.push(w); // sigla ou número: USB, TAA, 144, 1920
        return;
      }
      if (i > 0 && small.includes(w.toUpperCase())) {
        out.push(w.toLowerCase());
        return;
      }
      const cps = Array.from(w);
      out.push(cps[0]!.toUpperCase() + cps.slice(1).join('').toLowerCase());
    });
    return out.join(' ');
  }

  /**
   * Grava nome de usuário e e-mail na tabela `users` (identidade da conta, não preferência).
   * Recebe a entrada CRUA do POST — validar aqui evita trocar o dado real por um padrão do
   * catálogo. Devolve um aviso quando algo foi recusado, ou null quando tudo passou.
   */
  private async saveAccount(uid: number, raw: { username: string; email: string }): Promise<string | null> {
    const cur: Dict = await this.account(uid);
    if (uid <= 0 || Object.keys(cur).length === 0) return null;
    const user = raw.username.trim();
    const mail = raw.email.trim();
    const set: Record<string, string> = {};
    const bad: string[] = [];

    if (user !== '' && user !== cur.username) {
      if (/^[A-Za-z0-9_]{3,24}$/.test(user)) set.username = user;
      else bad.push('nome de usuário (3 a 24 caracteres: letras, números e _)');
    }
    if (mail !== '' && mail !== cur.email) {
      if (emailValido(mail) && mbLen(mail) <= 120) set.email = mail;
      else bad.push('e-mail');
    }
    if (Object.keys(set).length > 0) {
      try {
        const sql = 'UPDATE users SET ' + Object.keys(set).map((c) => `${c} = :${c}`).join(', ') + ' WHERE id = :u';
        await DB.run(sql, { ...set, u: uid });
        ctx().cache.delete('settings.account:' + uid);
      } catch {
        bad.push('nome de usuário ou e-mail já em uso');
      }
    }
    return bad.length === 0 ? null : 'Não foi possível atualizar: ' + bad.join('; ') + '.';
  }

  /**
   * Dados reais do usuário logado (users), usados nos campos de texto da aba CONTA.
   * Cache por requisição (o `static $accountCache` do PHP).
   */
  private async account(uid: number): Promise<Account> {
    const chave = 'settings.account:' + uid;
    const c = ctx().cache;
    if (c.has(chave)) return c.get(chave) as Account;
    let out: Account = {};
    if (uid > 0) {
      let row: Dict | null = null;
      try {
        row = await DB.one('SELECT username, email, last_login FROM users WHERE id = :u', { u: uid });
      } catch {
        row = null;
      }
      if (row !== null) {
        const last = String(row.last_login ?? '');
        // last_login é gravado por datetime('now') (UTC); a tela mostra no horário de Brasília.
        const ts = last !== '' ? Math.floor(Date.parse(last.replace(' ', 'T') + 'Z') / 1000) : NaN;
        out = {
          username: String(row.username ?? ''),
          email: String(row.email ?? ''),
          last_login: last !== '' ? date('d/m/Y H:i', Number.isNaN(ts) ? undefined : ts) : '',
        };
      }
    }
    c.set(chave, out);
    return out;
  }
}
