/**
 * LoadingIndicator Component
 * ===========================
 *
 * Small inline loading spinner with optional text.
 * Uses Bootstrap 4.6 spinner classes.
 */

import React from 'react';

interface LoadingIndicatorProps {
  text?: string;
}

export const LoadingIndicator: React.FC<LoadingIndicatorProps> = ({ text = 'Loading...' }) => (
  <div className="d-flex align-items-center text-muted small py-1">
    <div className="spinner-border spinner-border-sm mr-2" role="status">
      <span className="sr-only">{text}</span>
    </div>
    <span>{text}</span>
  </div>
);

export default LoadingIndicator;
