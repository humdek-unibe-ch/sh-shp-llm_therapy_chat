/**
 * GroupTabs – Group tab navigation with unread badges
 */

import React from 'react';
import type { TherapistGroup, UnreadCounts } from '../../types';

export interface GroupTabsProps {
  groups: TherapistGroup[];
  selectedGroupId: number | string | null;
  onSelectGroup: (groupId: number | string | null) => void;
  unreadByGroup: UnreadCounts['byGroup'];
  totalUnread: number;
  labels: {
    allGroupsTab: string;
  };
}

export const GroupTabs: React.FC<GroupTabsProps> = ({
  groups,
  selectedGroupId,
  onSelectGroup,
  unreadByGroup,
  totalUnread,
  labels,
}) => {
  if (groups.length === 0) return null;

  return (
    <ul className="nav nav-tabs mb-3">
      <li className="nav-item">
        <button
          className={`nav-link ${selectedGroupId === null ? 'active' : ''}`}
          onClick={() => onSelectGroup(null)}
        >
          {labels.allGroupsTab}
          {totalUnread > 0 && <span className="badge badge-primary ml-1">{totalUnread}</span>}
        </button>
      </li>
      {groups.map((g) => {
        const groupUnread =
          unreadByGroup?.[g.id_groups] ?? unreadByGroup?.[String(g.id_groups)] ?? 0;
        const isActive = String(selectedGroupId) === String(g.id_groups);
        
        return (
          <li key={g.id_groups} className="nav-item">
            <button
              className={`nav-link ${isActive ? 'active' : ''}`}
              onClick={() => onSelectGroup(g.id_groups)}
            >
              {g.group_name}
              {g.patient_count != null && (
                <span className="badge badge-light ml-1">{g.patient_count}</span>
              )}
              {groupUnread > 0 && <span className="badge badge-primary ml-1">{groupUnread}</span>}
            </button>
          </li>
        );
      })}
    </ul>
  );
};

export default GroupTabs;
