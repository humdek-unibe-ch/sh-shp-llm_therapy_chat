/**
 * Normalize escaped control sequences (e.g. "\\n") into real characters.
 * This is used for rendering model and message text consistently.
 */
export function normalizeEscapedText(content: string): string {
  if (!content || content.indexOf('\\') === -1) return content;

  return content
    .replace(/\\r\\n/g, '\n')
    .replace(/\\n/g, '\n')
    .replace(/\\r/g, '\r')
    .replace(/\\t/g, '\t');
}

