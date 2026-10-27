/**
 * FACÇÕES (/faccoes) — o App\Controllers\FactionsController do PHP (PRD v3 §27).
 *
 * Assinar/romper passa SEMPRE por confirmação explícita: o POST sem `confirm=1` é recusado com
 * 422. Não há migração direta entre facções: encerrar o contrato devolve o personagem ao Hunter
 * Command, e só a partir daí é possível assinar com a outra.
 */
import * as DB from '../core/database';
import * as GR from '../core/game-rules';
import { dataMock, flash, num, url } from '../core/helpers';
import { HttpException } from '../core/http-exception';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as OperationalRepo from '../repo/operational-repo';
import { faccoes } from '../views/pages/faccoes';
import { Controller } from './controller';

/** sprintf com %1$s / %2$s (os textos de confirmação usam argumentos posicionais). */
function sprintf(fmt: string, ...args: string[]): string {
  let i = 0;
  return fmt.replace(/%(?:(\d+)\$)?s/g, (_m, pos: string | undefined) => String(args[pos ? Number(pos) - 1 : i++] ?? ''));
}

/** strtotime($x) > time() para um DATETIME do D1 (UTC). */
function noFuturo(s: string): boolean {
  const t = Date.parse(s.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(s) ? '' : 'Z'));
  return !Number.isNaN(t) && t > Date.now();
}

export class FactionsController extends Controller {
  async index(_req: AppRequest, _p: Params = {}, status = 200): Promise<AppResponse> {
    const data = dataMock('faccoes');
    const op = await this.operational();
    data.operational = op;
    data.factions = [];

    for (const [slug, f] of Object.entries(GR.FACTIONS)) {
      const rep = Number(op.reputation[slug] ?? 0) | 0;
      const tier = String(op.tiers[slug] ?? 'SEM CONTRATO');
      const locked = op.locked[slug] ?? null;
      const lockedAtiva = locked !== null && noFuturo(String(locked));
      const ativa = (op.faction ?? null) === slug;
      const outra = (op.faction ?? null) !== null && !ativa;
      const surv = op.state === GR.STATE_SURVIVOR;

      const relKey = surv ? 'survivor' : lockedAtiva ? 'locked' : ativa ? 'active' : outra ? 'rival' : 'none';
      // Sem migração direta: para trocar de facção é preciso ENCERRAR o contrato atual.
      const ctaKey = surv ? 'survivor' : lockedAtiva ? 'locked' : ativa ? 'active' : outra ? 'blocked' : 'sign';

      data.factions.push({
        slug,
        label: f.label,
        color: f.color,
        tagline: f.tagline,
        identity: f.identity,
        economy: f.economy,
        missions: f.missions,
        npc: f.npc,
        reputation: rep,
        tier: ativa || rep > 0 ? GR.repTier(rep) : tier,
        next_tier: this.proximaFaixa(rep),
        relation_key: relKey,
        relation: String((data.relation ?? {})[relKey] ?? ''),
        active: ativa,
        locked_until: lockedAtiva ? String(locked) : null,
        cta_key: ctaKey,
        cta: String((data.cta ?? {})[ctaKey] ?? ''),
        can_sign: ctaKey === 'sign',
        needs_confirm: true,
        contracts: await this.contratos(slug),
        installations: await this.instalacoes(slug),
        confirm: this.textoConfirmacao((data.confirm ?? {}) as Dict, op, slug, f.label),
      });
    }

    const page = this.page('faccoes', 'Facções', { back: { label: 'VOLTAR', href: url('jogar') } });
    return this.render(faccoes, page, data, 'hud', status);
  }

  /** POST /faccoes/assinar — exige `faction` e `confirm=1`. */
  async sign(req: AppRequest, p: Params = {}): Promise<AppResponse> {
    this.csrf(req);
    const faction = String(req.input('faction', '') ?? '').toLowerCase();
    const confirm = req.input('confirm', '') === '1';
    const uid = this.userId();

    if (!GR.FACTIONS[faction]) throw new HttpException(404, 'Facção desconhecida');
    if (uid <= 0) {
      flash('error', 'Entre na sua conta para assinar um contrato.');
      return this.redirect('faccoes');
    }
    if (!confirm) {
      // A interface tem de pedir confirmação explícita (PRD §7.2). Sem ela: 422 e nada muda —
      // a própria tela volta, com o aviso (o layout parcial devolve JSON, como no PHP).
      flash('error', 'Confirme a troca de contrato antes de continuar.');
      return this.index(req, p, 422);
    }

    // Sem migração direta (regra de tela E de servidor): quem já tem contrato precisa encerrá-lo.
    const antes = await this.operational();
    const atual = antes.faction ?? null;
    if (atual !== null && atual !== faction) {
      flash('error', 'Encerre o contrato com ' + (GR.FACTIONS[atual]?.label ?? atual.toUpperCase()) + ' antes de assinar com ' + GR.FACTIONS[faction]!.label + '.');
      return this.redirect('faccoes');
    }
    const res = await OperationalRepo.sign(uid, faction, true);
    if (!res.ok) {
      flash('error', String(res.error ?? 'Não foi possível assinar o contrato.'));
      return this.redirect('faccoes');
    }
    const label = GR.FACTIONS[faction]!.label;
    const broke = String(res.broke ?? '');
    flash(
      'ok',
      broke !== ''
        ? 'CONTRATO ASSINADO: ' + label + '. Reputação com ' + (GR.FACTIONS[broke]?.label ?? '') + ' redefinida para 0.'
        : 'CONTRATO ASSINADO: ' + label + '. IFF do Hunter Command suspenso sob a Licença Cinza.',
    );
    return this.redirect('faccoes');
  }

  /** POST /faccoes/romper — encerra o contrato atual e devolve ao Hunter Command (não zera). */
  async release(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    this.csrf(req);
    const uid = this.userId();
    if (uid <= 0 || req.input('confirm', '') !== '1') {
      flash('error', 'Confirme o encerramento do contrato antes de continuar.');
    } else if (await OperationalRepo.release(uid)) {
      flash('ok', 'Contrato encerrado. Você volta a operar sob o Hunter Command.');
    } else {
      flash('error', 'Sistema de facções indisponível neste servidor. Tente novamente mais tarde.');
    }
    return this.redirect('faccoes');
  }

  // ------------------------------------------------------------------ apoio

  private async contratos(faction: string): Promise<number> {
    try {
      return Number(await DB.value('SELECT COUNT(*) FROM missions WHERE origin = :f', { f: faction })) | 0;
    } catch {
      return 0;
    }
  }

  private async instalacoes(faction: string): Promise<string[]> {
    try {
      const rows = await DB.all('SELECT label FROM map_markers WHERE kind = :k ORDER BY id', { k: faction });
      return rows.map((r) => String(r.label ?? '')).filter((l) => l !== '' && l !== '0');
    } catch {
      return [];
    }
  }

  private proximaFaixa(rep: number): string {
    for (const t of GR.REP_TIERS) {
      if (rep < t.min) return t.label + ' EM ' + num(t.min - rep) + ' REP';
    }
    return 'FAIXA MÁXIMA';
  }

  /** Texto do modal de confirmação, já com os nomes certos. */
  private textoConfirmacao(c: Dict, op: OperationalRepo.Operational, slug: string, label: string): Dict {
    const atual = GR.faction(op.faction ?? null);
    if (atual !== null && (op.faction ?? null) !== slug) {
      return {
        title: String(c.title ?? 'ROMPER CONTRATO?'),
        body: sprintf(String(c.body ?? ''), atual.label, label) + ' ' + sprintf(String(c.body_new ?? ''), atual.label, label),
        ok: sprintf(String(c.ok ?? 'ROMPER E ASSINAR %2$s'), atual.label, label),
        cancel: String(c.cancel ?? 'CANCELAR'),
      };
    }
    return {
      title: String(c.title_first ?? 'ASSINAR CONTRATO?'),
      body: sprintf(String(c.body_first ?? ''), '', label),
      ok: sprintf(String(c.ok_first ?? 'ASSINAR %2$s'), '', label),
      cancel: String(c.cancel ?? 'CANCELAR'),
    };
  }
}
