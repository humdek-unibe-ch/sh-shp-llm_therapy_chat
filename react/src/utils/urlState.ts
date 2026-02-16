/**
 * URL State Helpers
 * ==================
 *
 * Read/write `gid` (group) and `uid` (user/conversation) URL parameters
 * for the therapist dashboard. Uses replaceState so navigation doesn't
 * pollute browser history.
 */

export interface UrlState {
  gid?: number;
  uid?: number | string;
}

/** Read gid/uid from the current URL search params */
export function readUrlState(): UrlState {
  if (typeof window === 'undefined') return {};

  try {
    const sp = new URLSearchParams(window.location.search);
    const state: UrlState = {};

    const gidStr = sp.get('gid');
    if (gidStr != null) {
      const gid = Number(gidStr);
      if (!isNaN(gid)) state.gid = gid;
    }

    const uidStr = sp.get('uid');
    if (uidStr != null) {
      const uid = Number(uidStr);
      state.uid = isNaN(uid) ? uidStr : uid;
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
