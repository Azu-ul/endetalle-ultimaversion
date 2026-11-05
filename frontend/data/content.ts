import { InternalMessage } from '../types';
import { DEFAULT_AVATAR } from '../constants';

// Mensajes de ejemplo de MessagesPanel: se reemplazan por la mensajería real en el bloque 9
export const MESSAGES: InternalMessage[] = [
  { id: 'm-1', sender: 'Soporte Técnico', avatar: DEFAULT_AVATAR, text: 'Bienvenido al equipo Pro. ¿Necesitas ayuda con tu primera descarga?', time: '10:00 AM', unread: true }
];
