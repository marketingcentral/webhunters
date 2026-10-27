/**
 * CATÁLOGO FECHADO DE ESCOPOS — App\Core\Api\Escopos do PHP.
 *
 * Um escopo é `<recurso>:<verbo>`, com verbo `read` (GET) ou `write` (POST/PUT/PATCH/DELETE).
 * Não há coringa nem `*`, e `write` não inclui `read` (o porquê está no arquivo PHP).
 * ESTA LISTA É A FONTE ÚNICA: o que não estiver aqui não pode ser gravado numa chave
 * (normalizarEscopos descarta), nem exigido por um endpoint, nem aparece no painel.
 */

export const VERBOS = ['read', 'write'] as const;

export interface MetaRecurso {
  rotulo: string;
  descricao: string;
  icone: string;
}

/** Recursos governados pela API, na ordem em que a tela do painel os agrupa. A chave é contrato (URL). */
export const RECURSOS: Record<string, MetaRecurso> = {
  players: { rotulo: 'Jogadores', descricao: 'Contas, papel, situação e progresso.', icone: 'group' },
  bans: { rotulo: 'Banimentos', descricao: 'Histórico de punições, aplicar e revogar.', icone: 'gavel' },
  missions: { rotulo: 'Missões', descricao: 'Catálogo de missões do mundo.', icone: 'flag' },
  achievements: { rotulo: 'Conquistas', descricao: 'Catálogo de conquistas.', icone: 'military_tech' },
  maps: { rotulo: 'Mapas', descricao: 'Mapas e seus marcadores.', icone: 'map' },
  items: { rotulo: 'Itens', descricao: 'Catálogo de itens do inventário.', icone: 'inventory_2' },
  shop: { rotulo: 'Loja', descricao: 'Ofertas da loja.', icone: 'shopping_cart' },
  events: { rotulo: 'Eventos', descricao: 'Desafios, notícias e avisos do sistema.', icone: 'event' },
  servers: { rotulo: 'Servidores', descricao: 'Regiões, serviços e modo manutenção.', icone: 'dns' },
  economy: { rotulo: 'Economia', descricao: 'Transações e relatório de receita.', icone: 'payments' },
  settings: { rotulo: 'Configurações', descricao: 'Parâmetros do jogo (admin_settings).', icone: 'settings' },
};

let cacheTodos: string[] | null = null;

/** Todos os escopos válidos, na ordem do catálogo: ['players:read', 'players:write', ...]. */
export function todos(): string[] {
  if (cacheTodos) return cacheTodos;
  const out: string[] = [];
  for (const recurso of Object.keys(RECURSOS)) for (const verbo of VERBOS) out.push(`${recurso}:${verbo}`);
  return (cacheTodos = out);
}

export function existe(escopo: string): boolean {
  return todos().includes(escopo);
}

/** Rótulo humano para a tela: 'players:read' -> 'Jogadores · ler'. */
export function rotulo(escopo: string): string {
  const [recurso = '', verbo = ''] = escopo.split(':', 2);
  const nome = RECURSOS[recurso]?.rotulo ?? recurso;
  return `${nome} · ${verbo === 'write' ? 'escrever' : 'ler'}`;
}

/** O escopo que um método HTTP exige sobre um recurso: muda estado = `write`. Regra única. */
export function paraMetodo(recurso: string, metodo: string): string {
  return `${recurso}:${metodo.toUpperCase() === 'GET' ? 'read' : 'write'}`;
}

export interface GrupoEscopos {
  recurso: string;
  rotulo: string;
  descricao: string;
  icone: string;
  escopos: { valor: string; verbo: string }[];
}

/** Agrupado por recurso, pronto para desenhar o formulário de criação de chave. */
export function agrupados(): GrupoEscopos[] {
  return Object.entries(RECURSOS).map(([recurso, meta]) => ({
    recurso,
    rotulo: meta.rotulo,
    descricao: meta.descricao,
    icone: meta.icone,
    escopos: VERBOS.map((verbo) => ({ valor: `${recurso}:${verbo}`, verbo })),
  }));
}
