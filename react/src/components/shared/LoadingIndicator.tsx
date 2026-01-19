/**
 * Loading Indicator Component
 * ============================
 * 
 * Shows a typing/thinking indicator.
 */

import React from 'react';
import './LoadingIndicator.css';

interface LoadingIndicatorProps {
  text?: string;
}

export const LoadingIndicator: React.FC<LoadingIndicatorProps> = ({ 
  text = 'AI is thinking...' 
}) => {
  return (
    <div className="therapy-loading-indicator">
      <div className="therapy-loading-dots">
        <span className="dot"></span>
        <span className="dot"></span>
        <span className="dot"></span>
      </div>
      <span className="therapy-loading-text">{text}</span>
    </div>
  );
};

export default LoadingIndicator;
