/**
 * StatsHeader – Stats display at the top of the therapist dashboard
 */

import React from 'react';

export interface StatsHeaderProps {
  title: string;
  stats: {
    total: number;
    ai_enabled: number;
    ai_blocked: number;
    risk_critical: number;
  };
  unreadCounts: {
    total: number;
    totalAlerts: number;
  };
  labels: {
    title: string;
    statPatients: string;
    statAiEnabled: string;
    statAiBlocked: string;
    filterUnread: string;
    statCritical: string;
    statAlerts: string;
  };
}

const StatItem: React.FC<{ value: number; label: string; className?: string }> = ({
  value,
  label,
  className = '',
}) => (
  <div className="text-center">
    <div className={`h5 mb-0 ${className}`}>{value}</div>
    <small className="text-muted">{label}</small>
  </div>
);

export const StatsHeader: React.FC<StatsHeaderProps> = ({
  title,
  stats,
  unreadCounts,
  labels,
}) => (
  <div className="card border-0 shadow-sm mb-3">
    <div className="card-body d-flex justify-content-between align-items-center flex-wrap py-2">
      <h5 className="mb-0">
        <i className="fas fa-stethoscope text-primary mr-2" />
        {title}
      </h5>
      <div className="d-flex flex-wrap tc-flex-gap-md">
        <StatItem value={stats.total} label={labels.statPatients} />
        <StatItem value={stats.ai_enabled} label={labels.statAiEnabled} className="text-success" />
        <StatItem value={stats.ai_blocked} label={labels.statAiBlocked} className="text-warning" />
        <StatItem
          value={unreadCounts.total}
          label={labels.filterUnread}
          className={unreadCounts.total > 0 ? 'text-primary font-weight-bold' : ''}
        />
        <StatItem value={stats.risk_critical} label={labels.statCritical} className="text-danger" />
        <StatItem
          value={unreadCounts.totalAlerts}
          label={labels.statAlerts}
          className={unreadCounts.totalAlerts > 0 ? 'text-warning font-weight-bold' : 'text-warning'}
        />
      </div>
    </div>
  </div>
);

export default StatsHeader;
