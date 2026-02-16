/**
 * Modal Body Component
 * ====================
 *
 * Standardized modal body container with proper scrolling and padding.
 * Provides consistent content area for all modals.
 */

import React from 'react';

export interface ModalBodyProps {
  /** Content to render in the modal body */
  children: React.ReactNode;
  /** Additional CSS classes */
  className?: string;
  /** Maximum height for the body (enables scrolling) */
  maxHeight?: string;
  /** Whether to enable scrolling when content overflows */
  scrollable?: boolean;
}

/**
 * Standard modal body container
 */
export const ModalBody: React.FC<ModalBodyProps> = ({
  children,
  className = '',
  maxHeight,
  scrollable = true,
}) => {
  const style: React.CSSProperties = {};
  
  if (maxHeight) {
    style.maxHeight = maxHeight;
  }
  
  if (scrollable) {
    style.overflowY = 'auto';
  }

  return (
    <div 
      className={`tc-modal-body ${className}`}
      style={style}
    >
      {children}
    </div>
  );
};

export default ModalBody;
