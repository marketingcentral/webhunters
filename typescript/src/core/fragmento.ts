/**
 * FRAGMENTO DE LISTA — o App\Core\Fragmento do PHP: como o painel devolve só o pedaço da
 * tela que mudou quando o operador troca um filtro (assets/js/admin/lista.js).
 *
 * Mesma ideia do X-EH-Partial do jogo, um degrau abaixo: o MESMO controller e a MESMA view,
 * mas a resposta é JSON com só a região entre dois comentários HTML:
 *
 *   <!--eh:lista-->
 *   <section class="adm-card ..."> ... formulário, tabela e paginação ... </section>
 *   <!--/eh:lista-->
 *
 * Marcador de comentário (e não um <div>) porque não entra no fluxo do CSS e delimita
 * qualquer quantidade de irmãos. O formulário de filtros leva `data-adm-preservar="filtros"`.
 *
 * Quem usa: AdminController.render() (controllers/admin/admin-controller.ts) — nenhuma tela
 * precisa chamar nada daqui; basta escrever o par de marcadores na view.
 */
import { ctx } from './context';

/** Cabeçalho que pede o fragmento. */
export const CABECALHO = 'X-EH-Lista';

/** As duas bordas. Escritas LITERALMENTE nas views. */
export const ABRE = '<!--eh:lista-->';
export const FECHA = '<!--/eh:lista-->';

/** Esta requisição quer só a região da lista? */
export function pedido(): boolean {
  return ctx().req.header(CABECALHO) === '1';
}

/**
 * O HTML entre as duas bordas, ou null quando a tela renderizada não tem o par (dossiê de
 * jogador, confirmação de exclusão...). null é resposta legítima: o cliente navega de verdade.
 */
export function extrair(htmlPronto: string): string | null {
  let inicio = htmlPronto.indexOf(ABRE);
  if (inicio < 0) return null;
  inicio += ABRE.length;
  const fim = htmlPronto.indexOf(FECHA, inicio);
  if (fim < 0) return null;
  return htmlPronto.slice(inicio, fim);
}
