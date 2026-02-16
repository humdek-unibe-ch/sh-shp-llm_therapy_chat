/**
 * Modal Loading State Component
 * =============================
 *
 * Standardized loading state for modals with spinner and message.
 * Provides consistent loading experience across all modal dialogs.
 */

import React from 'react';
import type { ModalBodyProps } from './ModalBody';

export interface ModalLoadingStateProps extends Omit<ModalBodyProps, 'children'> {
  /** Loading message to display */
  message?: string;
  /** Optional sub-message or hint */
  subMessage?: string;
  /** Spinner size */
  size?: 'sm' | 'md' | 'lg';
  /** Spinner variant (color) */
  variant?: 'primary' | 'secondary' | 'success' | 'danger' | 'warning' | 'info' | 'light' | 'dark';
  /** Whether to center the loading state */
  centered?: boolean;
}

/**
 * Standardized loading state for modal content
 */
export const ModalLoadingState: React.FC<ModalLoadingStateProps> = ({
  message = 'Loading...',
  subMessage,
  size = 'lg',
  variant = 'secondary',
  centered = true,
  className = '',
  ...bodyProps
}) => {
  const getSpinnerClass = () => {
    const baseClass = 'spinner-border';
    const sizeClass = size === 'sm' ? 'spinner-border-sm' : size === 'lg' ? 'tc-spinner-lg' : '';
    const variantClass = `text-${variant}`;
    return `${baseClass} ${sizeClass} ${variantClass}`;
  };

  const containerClass = centered 
    ? 'd-flex flex-column align-items-center justify-content-center tc-flex-1'
    : 'd-flex align-items-center';

  return (
    <div className={`tc-modal-loading-state ${className}`}>
      <div className={containerClass}>
        <div className={getSpinnerClass()} role="status">
          <span className="sr-only">{message}</span>
        </div>
        
        {message && (
          <p className="text-muted mb-0 mt-3">
            {message}
          </p>
        )}
        
        {subMessage && (
          <small className="text-muted mt-1">
            {subMessage}
          </small>
        )}
      </div>
    </div>
  );
};

export default ModalLoadingState;
