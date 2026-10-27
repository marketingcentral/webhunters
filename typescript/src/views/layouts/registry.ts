import type { LayoutView } from '../../core/view';
import { auth, hud, landing, partial } from './index';

/** Layouts disponíveis (app/Views/layouts/*.php). 'admin'/'overlay' ainda não foram portados. */
export const LAYOUTS = { hud, auth, partial, landing } satisfies Record<string, LayoutView>;
export type LayoutName = Exclude<keyof typeof LAYOUTS, 'partial'>;
