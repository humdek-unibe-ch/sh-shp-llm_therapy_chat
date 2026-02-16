/**
 * Header Area Component
 * =====================
 *
 * Container for the dashboard header with stats, alerts, and controls.
 * Combines StatsHeader, AlertBanner, and GroupTabs into one header area.
 */

import React from 'react';
import type { ReactNode } from 'react';

export interface HeaderAreaProps {
  /** Stats header component */
  statsHeader: ReactNode;
  /** Alert banner component */
  alertBanner?: ReactNode;
  /** Group tabs component */
  groupTabs?: ReactNode;
  /** Export controls component */
  exportControls?: ReactNode;
  /** Additional CSS classes */
  className?: string;
}

/**
 * Header area container for dashboard top section
 */
export const HeaderArea: React.FC<HeaderAreaProps> = ({
  statsHeader,
  alertBanner,
  groupTabs,
  exportControls,
  className = '',
}) => {
  return (
    <div className={`tc-header-area ${className}`}>
      {/* Stats Header */}
      {statsHeader}

      {/* Alert Banner */}
      {alertBanner}

      {/* Group Tabs and Export Controls Row */}
      {(groupTabs || exportControls) && (
        <div className="d-flex justify-content-between align-items-center mb-3">
          {/* Group Tabs */}
          <div className="flex-grow-1">
            {groupTabs}
          </div>

          {/* Export Controls */}
          {exportControls}
        </div>
      )}
    </div>
  );
};

export default HeaderArea;
