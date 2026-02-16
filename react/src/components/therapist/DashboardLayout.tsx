/**
 * Dashboard Layout Component
 * ==========================
 *
 * Main layout container for the therapist dashboard.
 * Provides responsive grid layout and overall structure.
 */

import React from 'react';
import type { ReactNode } from 'react';

export interface DashboardLayoutProps {
  /** Header content */
  header: ReactNode;
  /** Sidebar content (patient list) */
  sidebar: ReactNode;
  /** Main content area (conversation) */
  main: ReactNode;
  /** Right sidebar content (notes/controls) */
  rightSidebar?: ReactNode;
  /** Additional CSS classes */
  className?: string;
}

/**
 * Main dashboard layout with responsive grid
 */
export const DashboardLayout: React.FC<DashboardLayoutProps> = ({
  header,
  sidebar,
  main,
  rightSidebar,
  className = '',
}) => {
  return (
    <div className={`tc-dashboard-layout ${className}`}>
      {/* Header */}
      {header}

      {/* Main Content Grid */}
      <div className="row tc-row-min-height">
        {/* Left Sidebar - Patient List */}
        <div className="col-md-4 col-lg-3 mb-3 mb-md-0">
          {sidebar}
        </div>

        {/* Main Content - Conversation */}
        <div className="col-md-8 col-lg-6 mb-3 mb-md-0">
          {main}
        </div>

        {/* Right Sidebar - Notes & Controls */}
        <div className="col-lg-3 d-none d-lg-block">
          {rightSidebar}
        </div>
      </div>
    </div>
  );
};

export default DashboardLayout;
