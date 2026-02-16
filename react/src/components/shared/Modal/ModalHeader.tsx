/**
 * Modal Header Component
 * =====================
 *
 * Standardized modal header with title and close button.
 * Provides consistent styling and accessibility across all modals.
 */

import React from 'react';

export interface ModalHeaderProps {
  /** Modal title (string or JSX) */
  title: React.ReactNode;
  /** Optional subtitle */
  subtitle?: string;
  /** Called when close button is clicked */
  onClose: () => void;
  /** Header background class (default: bg-secondary text-white) */
  bgClass?: string;
  /** Additional CSS classes */
  className?: string;
  /** Show close button */
  showCloseButton?: boolean;
  /** Custom close button content */
  closeButtonContent?: React.ReactNode;
}

/**
 * Standard modal header with title and close functionality
 */
export const ModalHeader: React.FC<ModalHeaderProps> = ({
  title,
  subtitle,
  onClose,
  bgClass = 'bg-secondary text-white',
  className = '',
  showCloseButton = true,
  closeButtonContent,
}) => {
  return (
    <div className={`tc-modal-header ${bgClass} ${className}`}>
      <div className="d-flex justify-content-between align-items-center">
        <div className="flex-grow-1">
          <h5 className="mb-0">
            {title}
            {subtitle && (
              <small className="ml-2 font-weight-normal">
                {subtitle}
              </small>
            )}
          </h5>
        </div>
        
        {showCloseButton && (
          <button
            type="button"
            className="close text-white ml-3"
            onClick={onClose}
            aria-label="Close modal"
          >
            {closeButtonContent || <span>&times;</span>}
          </button>
        )}
      </div>
    </div>
  );
};

export default ModalHeader;
