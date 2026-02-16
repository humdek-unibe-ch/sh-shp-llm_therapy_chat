/**
 * Modal Component
 * ===============
 *
 * Base modal container with overlay and accessibility features.
 * Provides the foundation for all modal dialogs in the application.
 */

import React, { useEffect, useRef } from 'react';
import ReactDOM from 'react-dom';

export interface ModalProps {
  /** Whether the modal is open */
  open: boolean;
  /** Called when modal should close */
  onClose: () => void;
  /** Modal title for accessibility */
  title?: string;
  /** Additional CSS classes */
  className?: string;
  /** Whether modal can be closed by clicking overlay */
  closeOnOverlayClick?: boolean;
  /** Whether modal can be closed with Escape key */
  closeOnEscape?: boolean;
  /** Content to render in the modal */
  children: React.ReactNode;
}

/**
 * Base Modal component with overlay, accessibility, and keyboard handling
 */
export const Modal: React.FC<ModalProps> = ({
  open,
  onClose,
  title,
  className = '',
  closeOnOverlayClick = true,
  closeOnEscape = true,
  children,
}) => {
  const modalRef = useRef<HTMLDivElement>(null);
  const overlayRef = useRef<HTMLDivElement>(null);

  // Handle Escape key
  useEffect(() => {
    if (!open || !closeOnEscape) return;

    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose();
      }
    };

    document.addEventListener('keydown', handleEscape);
    return () => document.removeEventListener('keydown', handleEscape);
  }, [open, closeOnEscape, onClose]);

  // Focus management
  useEffect(() => {
    if (!open) return;

    // Focus the modal when it opens
    if (modalRef.current) {
      modalRef.current.focus();
    }

    // Prevent body scroll
    document.body.style.overflow = 'hidden';
    
    return () => {
      document.body.style.overflow = '';
    };
  }, [open]);

  // Handle overlay click
  const handleOverlayClick = (event: React.MouseEvent) => {
    if (closeOnOverlayClick && event.target === overlayRef.current) {
      onClose();
    }
  };

  // Trap focus within modal
  const handleKeyDown = (event: React.KeyboardEvent) => {
    if (event.key === 'Tab') {
      const modal = modalRef.current;
      if (!modal) return;

      const focusableElements = modal.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );
      const firstElement = focusableElements[0] as HTMLElement;
      const lastElement = focusableElements[focusableElements.length - 1] as HTMLElement;

      if (event.shiftKey) {
        if (document.activeElement === firstElement) {
          event.preventDefault();
          lastElement?.focus();
        }
      } else {
        if (document.activeElement === lastElement) {
          event.preventDefault();
          firstElement?.focus();
        }
      }
    }
  };

  if (!open) return null;

  // Use portal to render at document body level
  return ReactDOM.createPortal(
    <div
      className="tc-modal-overlay"
      ref={overlayRef}
      onClick={handleOverlayClick}
      role="dialog"
      aria-modal="true"
      aria-labelledby={title ? 'modal-title' : undefined}
      tabIndex={-1}
    >
      <div
        ref={modalRef}
        className={`tc-modal-box ${className}`}
        onKeyDown={handleKeyDown}
        tabIndex={-1}
      >
        {title && (
          <h2 id="modal-title" className="sr-only">
            {title}
          </h2>
        )}
        {children}
      </div>
    </div>,
    document.body
  );
};

export default Modal;
