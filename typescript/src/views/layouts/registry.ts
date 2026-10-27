import type { LayoutView } from '../../core/view';
import { admin, adminAuth } from './admin';
import { auth, hud, landing, partial } from './index';
import { overlay } from './overlay';

/** Layouts disponíveis (app/Views/layouts/*.php). */
export const LAYOUTS = { hud, auth, partial, landing, overlay, admin, 'admin-auth': adminAuth } satisfies Record<string, LayoutView>;
export type LayoutName = Exclude<keyof typeof LAYOUTS, 'partial'>;
