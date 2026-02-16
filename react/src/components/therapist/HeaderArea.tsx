/**
 * Header Area Component
 * =====================
 *
 * Container for the dashboard header with stats, alerts, and group tabs.
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
  className = '',
}) => {
  return (
    <div className={`tc-header-area ${className}`}>
      {statsHeader}
      {alertBanner}
      {groupTabs && (
        <div className="mb-3">
          {groupTabs}
        </div>
      )}
    </div>
  );
};

export default HeaderArea;
