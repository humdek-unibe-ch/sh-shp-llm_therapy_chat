/**
 * Risk & AI Controls Component
 * ============================
 *
 * Controls for managing conversation risk level and AI mode.
 * The AI toggle (`ai_enabled`) is the single source of truth for
 * whether the AI responds. When danger is detected the system sets
 * `ai_enabled = false` and blocks the conversation; the therapist
 * can re-enable AI here, which also unblocks the conversation.
 */

import React from 'react';
import type { RiskLevel, TherapistDashboardLabels, TherapistFeatures } from '../../types';

export interface RiskStatusControlsProps {
  /** Current conversation risk level */
  riskLevel: RiskLevel;
  /** Whether AI is enabled for this conversation */
  aiEnabled: boolean;
  /** Dashboard labels */
  labels: TherapistDashboardLabels;
  /** Dashboard features */
  features: TherapistFeatures;
  /** Called when risk level is changed */
  onSetRisk: (risk: RiskLevel) => void;
  /** Called when AI is toggled */
  onToggleAI: () => void;
  /** Additional CSS classes */
  className?: string;
}

/**
 * Risk and AI controls for conversation management
 */
export const RiskStatusControls: React.FC<RiskStatusControlsProps> = ({
  riskLevel,
  aiEnabled,
  labels,
  features,
  onSetRisk,
  onToggleAI,
  className = '',
}) => {
  const getRiskButtonColor = (risk: RiskLevel, isActive: boolean): string => {
    const colors: Record<RiskLevel, { active: string; inactive: string }> = {
      low: { active: 'btn-success', inactive: 'btn-outline-success' },
      medium: { active: 'btn-warning', inactive: 'btn-outline-warning' },
      high: { active: 'btn-danger', inactive: 'btn-outline-danger' },
      critical: { active: 'btn-danger', inactive: 'btn-outline-danger' },
    };
    return isActive ? colors[risk].active : colors[risk].inactive;
  };

  return (
    <div className={`tc-risk-status-controls ${className}`}>
      {/* Risk Control */}
      {features.enableRiskControl && (
        <div className="card border-0 shadow-sm mb-3">
          <div className="card-header bg-light py-2">
            <h6 className="mb-0">
              <i className="fas fa-shield-alt mr-2" />
              {labels.riskHeading}
            </h6>
          </div>
          <div className="card-body p-2 d-flex flex-wrap tc-flex-gap-xs">
            {(['low', 'medium', 'high', 'critical'] as RiskLevel[]).map((risk) => {
              const isActive = riskLevel === risk;
              const buttonClass = getRiskButtonColor(risk, isActive);
              const labelKey = `risk${risk.charAt(0).toUpperCase() + risk.slice(1)}` as keyof typeof labels;
              
              return (
                <button
                  key={risk}
                  className={`btn btn-sm ${buttonClass}`}
                  onClick={() => onSetRisk(risk)}
                  aria-pressed={isActive}
                >
                  {labels[labelKey] || risk}
                </button>
              );
            })}
          </div>
        </div>
      )}

      {/* AI Toggle – single source of truth for AI on/off */}
      {features.enableAiToggle && (
        <div className="card border-0 shadow-sm mb-3">
          <div className="card-header bg-light py-2">
            <h6 className="mb-0">
              <i className="fas fa-robot mr-2" />
              AI Mode
            </h6>
          </div>
          <div className="card-body p-2">
            <button
              className={`btn btn-sm btn-block ${aiEnabled ? 'btn-success' : 'btn-outline-warning'}`}
              onClick={onToggleAI}
              aria-pressed={aiEnabled}
            >
              <i className={`fas ${aiEnabled ? 'fa-toggle-on' : 'fa-toggle-off'} mr-1`} />
              {aiEnabled ? labels.aiModeIndicator : labels.humanModeIndicator}
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

export default RiskStatusControls;
