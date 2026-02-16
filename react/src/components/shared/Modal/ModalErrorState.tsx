/**
 * Modal Error State Component
 * ===========================
 *
 * Standardized error state for modals with alert and retry option.
 * Provides consistent error handling experience across all modal dialogs.
 */

import React from 'react';

export interface ModalErrorStateProps {
  /** Error message to display */
  error: string;
  /** Called when retry button is clicked */
  onRetry?: () => void;
  /** Retry button text */
  retryText?: string;
  /** Whether to show retry button */
  showRetry?: boolean;
  /** Error variant (affects alert styling) */
  variant?: 'primary' | 'secondary' | 'success' | 'danger' | 'warning' | 'info' | 'light' | 'dark';
  /** Additional CSS classes */
  className?: string;
  /** Whether to center the error state */
  centered?: boolean;
  /** Custom icon to display */
  icon?: React.ReactNode;
}

/**
 * Standardized error state for modal content
 */
export const ModalErrorState: React.FC<ModalErrorStateProps> = ({
  error,
  onRetry,
  retryText = 'Retry',
  showRetry = !!onRetry,
  variant = 'danger',
  className = '',
  centered = true,
  icon,
}) => {
  const containerClass = centered 
    ? 'd-flex flex-column align-items-center justify-content-center tc-flex-1'
    : 'd-flex align-items-start';

  const alertClass = `alert alert-${variant} mb-3 tc-alert-max-width`;

  return (
    <div className={`tc-modal-error-state ${className}`}>
      <div className={containerClass}>
        <div className={alertClass} role="alert">
          {icon || <i className="fas fa-exclamation-triangle mr-2" />}
          {error}
        </div>
        
        {showRetry && onRetry && (
          <button
            className="btn btn-secondary"
            onClick={onRetry}
          >
            <i className="fas fa-redo mr-1" />
            {retryText}
          </button>
        )}
      </div>
    </div>
  );
};

export default ModalErrorState;
