/**
 * URL State Helpers
 * ==================
 *
 * Read/write `gid` (group) and `uid` (user/conversation) URL parameters
 * for the therapist dashboard. Uses replaceState so navigation doesn't
 * pollute browser history.
 */

export interface UrlState {
  /** Group ID – kept as the raw string so "0000000005" matches backend IDs */
  gid?: number | string;
  /** User/conversation ID – kept as raw string for padded IDs */
  uid?: number | string;
}

/** Read gid/uid from the current URL search params (preserves padded strings) */
export function readUrlState(): UrlState {
  if (typeof window === 'undefined') return {};

  try {
    const sp = new URLSearchParams(window.location.search);
    const state: UrlState = {};

    const gidStr = sp.get('gid');
    if (gidStr != null && gidStr !== '') {
      // Keep as number if it's a plain number, otherwise keep original string
      // This ensures "6" -> 6 but "0000000005" stays as string
      const gid = Number(gidStr);
      state.gid = (!isNaN(gid) && String(gid) === gidStr) ? gid : gidStr;
    }

    const uidStr = sp.get('uid');
    if (uidStr != null && uidStr !== '') {
      const uid = Number(uidStr);
      state.uid = (!isNaN(uid) && String(uid) === uidStr) ? uid : uidStr;
    }

    return state;
  } catch {
    return {};
  }
}

/**
 * Write gid/uid to the URL without creating a history entry.
 * Only the keys present in `state` are touched; missing keys are removed.
 */
export function writeUrlState(state: Partial<UrlState>): void {
  if (typeof window === 'undefined') return;

  try {
    const sp = new URLSearchParams(window.location.search);

    if (state.gid !== undefined) sp.set('gid', String(state.gid));
    else sp.delete('gid');

    if (state.uid !== undefined) sp.set('uid', String(state.uid));
    else sp.delete('uid');

    const newUrl = `${window.location.pathname}?${sp.toString()}${window.location.hash}`;
    window.history.replaceState(null, '', newUrl);
  } catch {
    // silently ignore
  }
}
