/**
 * MarkdownRenderer Component
 * ============================
 *
 * Renders markdown content (used for AI responses, summaries, notes).
 * Uses react-markdown with GitHub-flavored markdown and HTML support.
 * HTML tags like <br> in stored content are rendered properly via rehype-raw.
 */

import React from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import rehypeRaw from 'rehype-raw';
import { normalizeEscapedText } from '../../utils/text';

interface MarkdownRendererProps {
  content: string;
}

export const MarkdownRenderer: React.FC<MarkdownRendererProps> = ({ content }) => {
  if (!content) return null;
  const normalized = normalizeEscapedText(content);

  return (
    <div className="tc-markdown">
      <ReactMarkdown remarkPlugins={[remarkGfm]} rehypePlugins={[rehypeRaw]}>
        {normalized}
      </ReactMarkdown>
    </div>
  );
};

export default MarkdownRenderer;
