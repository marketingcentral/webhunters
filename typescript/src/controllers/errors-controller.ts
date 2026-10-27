/**
 * Páginas de erro — o App\Controllers\ErrorsController do PHP.
 */
import { ctx } from '../core/context';
import { dataMock, url } from '../core/helpers';
import { e } from '../core/html';
import { HttpException } from '../core/http-exception';
import type { AppRequest } from '../core/request';
import { AppResponse } from '../core/response';
import { nonce } from '../core/security';
import type { Dict } from '../core/view';
import { erro } from '../views/pages/auth';
import { Controller } from './controller';

const TEXT: Record<number, [string, string]> = {
  403: ['ACESSO NEGADO', 'Esta área é restrita. Sua conta não tem permissão para acessá-la.'],
  404: ['ÁREA NÃO MAPEADA', 'A coordenada que você tentou acessar não existe ou foi evacuada. Verifique a rota e tente novamente.'],
  405: ['MANOBRA INVÁLIDA', 'Este ponto não aceita esse tipo de requisição. Volte e use os controles da interface.'],
  419: ['SESSÃO EXPIRADA', 'Seu token de segurança expirou ou é inválido. Recarregue a página e envie o formulário novamente.'],
  500: ['FALHA NO SISTEMA', 'Ocorreu um erro interno. Nossa equipe já foi notificada. Tente novamente em instantes.'],
  503: ['MANUTENÇÃO EM CURSO', 'O jogo está fora do ar para manutenção. Tente novamente em instantes.'],
};

export class ErrorsController extends Controller {
  async fromException(ex: HttpException, req: AppRequest): Promise<AppResponse> {
    let status = ex.status;
    if (!TEXT[status]) status = status >= 500 ? 500 : 404;
    const detail = [419, 503].includes(status) && ex.message !== '' ? ex.message : null;
    let r = await this.errorPage(status, req, detail);
    for (const [k, v] of Object.entries(ex.headers)) r = r.withHeader(k, v);
    return r;
  }

  async serverError(err: unknown, req: AppRequest): Promise<AppResponse> {
    const detail = ctx().debug ? String((err as Error)?.stack ?? err) : null;
    try {
      return await this.errorPage(500, req, detail);
    } catch {
      const body =
        '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>500 · Extract Hunters</title>' +
        `<style nonce="${e(nonce())}">body{background:#070907;color:#e8ede6;font:16px/1.5 system-ui;display:grid;place-items:center;min-height:100vh;margin:0}` +
        'main{max-width:36rem;padding:2rem;border:1px solid rgba(155,224,74,.35)}h1{color:#9be04a;letter-spacing:.06em}</style></head>' +
        `<body><main><h1>FALHA NO SISTEMA</h1><p>${e(TEXT[500]![1])}</p>` +
        (detail ? `<pre>${e(detail)}</pre>` : '') +
        `<p><a href="${e(url('inicio'))}" style="color:#9be04a">Voltar ao início</a></p></main></body></html>`;
      return AppResponse.html(body, 500);
    }
  }

  private async errorPage(status: number, req: AppRequest, detail: string | null): Promise<AppResponse> {
    let [title, message] = TEXT[status]!;
    const mock = dataMock('404');
    const texts: Dict = mock.codes?.[status] ?? {};
    title = String(texts.title ?? '').trim() || title;
    message = String(texts.message ?? '').trim() || message;

    let links = ((mock.links ?? []) as Dict[])
      .filter((l) => l && String(l.label ?? '').trim() !== '')
      .map((l) => ({ label: String(l.label), href: url(String(l.href ?? 'inicio')), icon: String(l.icon ?? 'chevron_right') }));
    if (!links.length) {
      links = [
        { label: 'VOLTAR AO INÍCIO', href: url('inicio'), icon: 'flag' },
        { label: 'JOGAR', href: url('jogar'), icon: 'sports_esports' },
        { label: 'SUPORTE', href: url('suporte'), icon: 'headset_mic' },
      ];
    }
    const page = this.page('404', `${status} · ${title}`, {
      slug: '404',
      nav: '',
      back: { label: 'VOLTAR', href: url('inicio') },
      three: { atmosphere: true },
    });
    const data = {
      ...mock,
      code: status,
      title,
      message,
      status: String(texts.status ?? ''),
      accent: String(texts.accent ?? 'lime'),
      detail,
      path: req.path(),
      links,
    };
    return this.render(erro, page, data, 'auth', status);
  }
}
