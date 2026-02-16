/**
 * Modal Footer Component
 * =====================
 *
 * Standardized modal footer with action buttons.
 * Provides consistent button layout and spacing.
 */

import React from 'react';

export interface ModalFooterProps {
  /** Footer content (usually buttons) */
  children: React.ReactNode;
  /** Additional CSS classes */
  className?: string;
  /** Layout direction for buttons */
  direction?: 'row' | 'column';
  /** Alignment of buttons */
  alignment?: 'left' | 'center' | 'right' | 'space-between';
}

/**
 * Standard modal footer container
 */
export const ModalFooter: React.FC<ModalFooterProps> = ({
  children,
  className = '',
  direction = 'row',
  alignment = 'right',
}) => {
  const getAlignmentClass = () => {
    switch (alignment) {
      case 'left':
        return 'justify-content-start';
      case 'center':
        return 'justify-content-center';
      case 'right':
        return 'justify-content-end';
      case 'space-between':
        return 'justify-content-between';
      default:
        return 'justify-content-end';
    }
  };

  const getDirectionClass = () => {
    return direction === 'column' ? 'flex-column' : '';
  };

  return (
    <div 
      className={`tc-modal-footer d-flex ${getDirectionClass()} ${getAlignmentClass()} ${className}`}
    >
      {children}
    </div>
  );
};

export default ModalFooter;
