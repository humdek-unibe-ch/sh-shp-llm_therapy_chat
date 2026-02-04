/**
 * Therapist Dashboard Component
 * ==============================
 * 
 * Dashboard for therapists to monitor and communicate with patients.
 * Features:
 * - Conversation list with filtering
 * - Real-time message viewing with unread counts
 * - Alert management
 * - Notes and controls
 * - AI toggle and risk management
 * - Invisible monitoring mode
 * - Per-subject unread message tracking
 */

import React, { useState, useEffect, useCallback } from 'react';
import { Container, Row, Col, Card, ListGroup, Badge, Button, Form, Alert, ButtonGroup, Dropdown } from 'react-bootstrap';
import { MessageList } from '../shared/MessageList';
import { MessageInput } from '../shared/MessageInput';
import { LoadingIndicator } from '../shared/LoadingIndicator';
import { useChatState } from '../../hooks/useChatState';
import { usePolling } from '../../hooks/usePolling';
import { therapistDashboardApi } from '../../utils/api';
import type { TherapistDashboardConfig, Conversation, Alert as AlertType, Tag, Note, RiskLevel, ConversationStatus, UnreadCounts } from '../../types';
import './TherapistDashboard.css';

// Filter types
type FilterType = 'all' | 'active' | 'critical' | 'unread' | 'tagged';

interface TherapistDashboardProps {
  config: TherapistDashboardConfig;
}

export const TherapistDashboard: React.FC<TherapistDashboardProps> = ({ config }) => {
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [selectedConversationId, setSelectedConversationId] = useState<number | string | null>(
    config.selectedSubjectId ?? null
  );
  const [alerts, setAlerts] = useState<AlertType[]>([]);
  const [tags, setTags] = useState<Tag[]>([]);
  const [notes, setNotes] = useState<Note[]>([]);
  const [newNote, setNewNote] = useState('');
  const [isLoadingList, setIsLoadingList] = useState(true);
  const [listError, setListError] = useState<string | null>(null);
  const [activeFilter, setActiveFilter] = useState<FilterType>('all');
  const [isInvisibleMode, setIsInvisibleMode] = useState(false);
  const [unreadCounts, setUnreadCounts] = useState<UnreadCounts>({ total: 0, bySubject: {} });

  // Feature toggles from config
  const { features, labels } = config;

  const {
    conversation,
    messages,
    isLoading,
    isSending,
    error,
    loadConversation,
    sendMessage,
    clearError,
  } = useChatState({ config, isTherapist: true });

  /**
   * Load conversations list with optional filter
   */
  const loadConversations = useCallback(async (filter?: FilterType) => {
    setIsLoadingList(true);
    try {
      const response = await therapistDashboardApi.getConversations(config.sectionId, {
        filter: filter || activeFilter,
        limit: config.conversationsPerPage || 20,
      });
      setConversations(response.conversations || []);
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to load conversations';
      setListError(message);
    } finally {
      setIsLoadingList(false);
    }
  }, [config.sectionId, config.conversationsPerPage, activeFilter]);

  /**
   * Load alerts
   */
  const loadAlerts = useCallback(async () => {
    try {
      const response = await therapistDashboardApi.getAlerts(config.sectionId, true);
      setAlerts(response.alerts || []);
    } catch (err) {
      console.error('Load alerts error:', err);
    }
  }, [config.sectionId]);

  /**
   * Load tags
   */
  const loadTags = useCallback(async () => {
    try {
      const response = await therapistDashboardApi.getTags(config.sectionId);
      setTags(response.tags || []);
    } catch (err) {
      console.error('Load tags error:', err);
    }
  }, [config.sectionId]);

  /**
   * Load unread counts per subject
   */
  const loadUnreadCounts = useCallback(async () => {
    try {
      const response = await therapistDashboardApi.getUnreadCounts(config.sectionId);
      setUnreadCounts(response.unread_counts || { total: 0, bySubject: {} });
    } catch (err) {
      console.error('Load unread counts error:', err);
    }
  }, [config.sectionId]);

  /**
   * Load notes for selected conversation
   */
  const loadNotes = useCallback(async (conversationId: number | string) => {
    try {
      const response = await therapistDashboardApi.getNotes(config.sectionId, conversationId);
      setNotes(response.notes || []);
    } catch (err) {
      console.error('Load notes error:', err);
    }
  }, [config.sectionId]);

  /**
   * Initial load
   */
  useEffect(() => {
    loadConversations();
    loadAlerts();
    loadTags();
    loadUnreadCounts();
  }, [loadConversations, loadAlerts, loadTags, loadUnreadCounts]);

  /**
   * Load selected conversation
   */
  useEffect(() => {
    if (selectedConversationId) {
      loadConversation(selectedConversationId);
      loadNotes(selectedConversationId);
    }
  }, [selectedConversationId, loadConversation, loadNotes]);

  /**
   * Set up polling for alerts, tags, and unread counts
   */
  usePolling({
    callback: async () => {
      await loadAlerts();
      await loadTags();
      await loadUnreadCounts();
      if (selectedConversationId) {
        // Poll messages handled by useChatState
      }
    },
    interval: config.pollingInterval,
    enabled: true,
  });

  /**
   * Handle conversation selection
   */
  const handleSelectConversation = useCallback(async (convId: number | string) => {
    setSelectedConversationId(convId);
    
    // Mark messages as read when conversation is selected
    try {
      await therapistDashboardApi.markMessagesRead(config.sectionId, convId);
      // Refresh unread counts
      loadUnreadCounts();
    } catch (err) {
      console.error('Mark messages read error:', err);
    }
  }, [config.sectionId, loadUnreadCounts]);

  /**
   * Handle toggle AI
   */
  const handleToggleAI = useCallback(async () => {
    if (!conversation?.id) return;

    try {
      const newEnabled = !conversation.ai_enabled;
      await therapistDashboardApi.toggleAI(config.sectionId, conversation.id, newEnabled);
      loadConversation(conversation.id);
    } catch (err) {
      console.error('Toggle AI error:', err);
    }
  }, [config.sectionId, conversation, loadConversation]);

  /**
   * Handle set risk level
   */
  const handleSetRisk = useCallback(async (riskLevel: RiskLevel) => {
    if (!conversation?.id || !features.enableRiskControl) return;

    try {
      await therapistDashboardApi.setRiskLevel(config.sectionId, conversation.id, riskLevel);
      loadConversation(conversation.id);
      loadConversations();
    } catch (err) {
      console.error('Set risk error:', err);
    }
  }, [config.sectionId, conversation, loadConversation, loadConversations, features.enableRiskControl]);

  /**
   * Handle set status
   */
  const handleSetStatus = useCallback(async (status: ConversationStatus) => {
    if (!conversation?.id || !features.enableStatusControl) return;

    try {
      await therapistDashboardApi.setStatus(config.sectionId, conversation.id, status);
      loadConversation(conversation.id);
      loadConversations();
    } catch (err) {
      console.error('Set status error:', err);
    }
  }, [config.sectionId, conversation, loadConversation, loadConversations, features.enableStatusControl]);

  /**
   * Handle filter change
   */
  const handleFilterChange = useCallback((filter: FilterType) => {
    setActiveFilter(filter);
    loadConversations(filter);
  }, [loadConversations]);

  /**
   * Get filtered conversations
   */
  const getFilteredConversations = useCallback(() => {
    // Filtering is done server-side, but we can do client-side backup
    return conversations;
  }, [conversations]);

  /**
   * Handle add note
   */
  const handleAddNote = useCallback(async () => {
    if (!conversation?.id || !newNote.trim()) return;

    try {
      await therapistDashboardApi.addNote(config.sectionId, conversation.id, newNote.trim());
      setNewNote('');
      loadNotes(conversation.id);
    } catch (err) {
      console.error('Add note error:', err);
    }
  }, [config.sectionId, conversation, newNote, loadNotes]);

  /**
   * Handle acknowledge tag
   */
  const handleAcknowledgeTag = useCallback(async (tagId: number) => {
    try {
      await therapistDashboardApi.acknowledgeTag(config.sectionId, tagId);
      loadTags();
    } catch (err) {
      console.error('Acknowledge tag error:', err);
    }
  }, [config.sectionId, loadTags]);

  /**
   * Handle mark alert read
   */
  const handleMarkAlertRead = useCallback(async (alertId: number) => {
    try {
      await therapistDashboardApi.markAlertRead(config.sectionId, alertId);
      loadAlerts();
    } catch (err) {
      console.error('Mark alert read error:', err);
    }
  }, [config.sectionId, loadAlerts]);

  /**
   * Get risk badge
   */
  const getRiskBadge = (risk: RiskLevel) => {
    const variants: Record<RiskLevel, string> = {
      low: 'success',
      medium: 'warning',
      high: 'danger',
      critical: 'danger',
    };
    
    const riskLabels: Record<RiskLevel, string> = {
      low: labels.riskLow,
      medium: labels.riskMedium,
      high: labels.riskHigh,
      critical: labels.riskCritical,
    };
    
    return (
      <Badge variant={variants[risk]} className={risk === 'critical' ? 'font-weight-bold' : ''}>
        {risk === 'critical' && <i className="fas fa-exclamation-triangle mr-1"></i>}
        {riskLabels[risk] || risk}
      </Badge>
    );
  };

  /**
   * Get status badge
   */
  const getStatusBadge = (status: ConversationStatus) => {
    const variants: Record<ConversationStatus, string> = {
      active: 'success',
      paused: 'warning',
      closed: 'secondary',
    };
    
    const statusLabels: Record<ConversationStatus, string> = {
      active: labels.statusActive,
      paused: labels.statusPaused,
      closed: labels.statusClosed,
    };
    
    return (
      <Badge variant={variants[status]}>
        {statusLabels[status] || status}
      </Badge>
    );
  };

  return (
    <Container fluid className="therapist-dashboard py-3">
      {/* Header with Stats */}
      {features.showStatsHeader && (
        <Row className="mb-3">
          <Col>
            <Card className="border-0 shadow-sm">
              <Card.Body className="d-flex justify-content-between align-items-center flex-wrap">
                <h4 className="mb-0">
                  <i className="fas fa-stethoscope text-primary mr-2"></i>
                  {labels.title}
                </h4>
                <div className="d-flex gap-4 flex-wrap">
                  <div className="text-center px-3">
                    <div className="h4 mb-0">{config.stats.total}</div>
                    <small className="text-muted">{labels.statPatients}</small>
                  </div>
                  <div className="text-center px-3">
                    <div className="h4 mb-0 text-success">{config.stats.active}</div>
                    <small className="text-muted">{labels.statActive}</small>
                  </div>
                  {/* Unread messages count - NEW */}
                  <div className="text-center px-3">
                    <div className={`h4 mb-0 ${unreadCounts.total > 0 ? 'text-primary font-weight-bold' : ''}`}>
                      {unreadCounts.total}
                    </div>
                    <small className="text-muted">Unread</small>
                  </div>
                  <div className="text-center px-3">
                    <div className="h4 mb-0 text-danger">{config.stats.risk_critical}</div>
                    <small className="text-muted">{labels.statCritical}</small>
                  </div>
                  <div className="text-center px-3">
                    <div className="h4 mb-0 text-warning">{alerts.length}</div>
                    <small className="text-muted">{labels.statAlerts}</small>
                  </div>
                  <div className="text-center px-3">
                    <div className="h4 mb-0 text-info">{tags.length}</div>
                    <small className="text-muted">{labels.statTags}</small>
                  </div>
                </div>
              </Card.Body>
            </Card>
          </Col>
        </Row>
      )}

      {/* Alerts Banner */}
      {features.showAlertsPanel && (alerts.length > 0 || tags.length > 0) && (
        <Row className="mb-3">
          <Col>
            {alerts.filter(a => a.severity === 'critical' || a.severity === 'emergency').map((alert) => (
              <Alert key={alert.id} variant="danger" className="mb-2 d-flex justify-content-between align-items-center">
                <div>
                  <i className="fas fa-exclamation-triangle mr-2"></i>
                  <strong>{alert.subject_name}:</strong> {alert.message}
                </div>
                <Button variant="outline-light" size="sm" onClick={() => handleMarkAlertRead(alert.id)}>
                  <i className="fas fa-check mr-1"></i> {labels.dismiss}
                </Button>
              </Alert>
            ))}
            {tags.filter(t => !t.acknowledged).map((tag) => (
              <Alert key={tag.id} variant="warning" className="mb-2 d-flex justify-content-between align-items-center">
                <div>
                  <i className="fas fa-at mr-2"></i>
                  <strong>{tag.subject_name}:</strong> {tag.tag_reason || 'Tagged you'}
                </div>
                <Button variant="outline-dark" size="sm" onClick={() => handleAcknowledgeTag(tag.id)}>
                  <i className="fas fa-check mr-1"></i> {labels.acknowledge}
                </Button>
              </Alert>
            ))}
          </Col>
        </Row>
      )}

      <Row className="h-100">
        {/* Conversations Sidebar */}
        <Col md={4} lg={3}>
          <Card className="border-0 shadow-sm h-100">
            <Card.Header className="bg-light">
              <div className="d-flex justify-content-between align-items-center mb-2">
                <h6 className="mb-0">
                  <i className="fas fa-comments mr-2"></i>
                  {labels.conversationsHeading}
                </h6>
                {features.enableInvisibleMode && (
                  <Button
                    variant={isInvisibleMode ? 'outline-secondary' : 'outline-primary'}
                    size="sm"
                    onClick={() => setIsInvisibleMode(!isInvisibleMode)}
                    title={isInvisibleMode ? 'Visible mode' : 'Invisible mode - observe without notification'}
                  >
                    <i className={`fas ${isInvisibleMode ? 'fa-eye-slash' : 'fa-eye'}`}></i>
                  </Button>
                )}
              </div>
              {/* Filter buttons */}
              <ButtonGroup size="sm" className="w-100">
                <Button
                  variant={activeFilter === 'all' ? 'primary' : 'outline-secondary'}
                  onClick={() => handleFilterChange('all')}
                >
                  {labels.filterAll}
                </Button>
                <Button
                  variant={activeFilter === 'active' ? 'primary' : 'outline-secondary'}
                  onClick={() => handleFilterChange('active')}
                >
                  {labels.filterActive}
                </Button>
                <Button
                  variant={activeFilter === 'critical' ? 'danger' : 'outline-secondary'}
                  onClick={() => handleFilterChange('critical')}
                >
                  {labels.filterCritical}
                </Button>
                <Button
                  variant={activeFilter === 'tagged' ? 'warning' : 'outline-secondary'}
                  onClick={() => handleFilterChange('tagged')}
                >
                  {labels.filterTagged}
                </Button>
              </ButtonGroup>
            </Card.Header>
            <ListGroup variant="flush" className="therapist-conversation-list">
              {isLoadingList ? (
                <div className="p-3 text-center text-muted">
                  <div className="spinner-border spinner-border-sm" role="status">
                    <span className="sr-only">{labels.loading}</span>
                  </div>
                </div>
              ) : listError ? (
                <div className="p-3 text-center text-danger">{listError}</div>
              ) : getFilteredConversations().length === 0 ? (
                <div className="p-3 text-center text-muted">{labels.noConversations}</div>
              ) : (
                getFilteredConversations().map((conv) => {
                  // Get unread count for this subject
                  const subjectUnread = unreadCounts.bySubject[conv.id_users ?? 0];
                  const unreadMessageCount = subjectUnread?.unreadCount ?? 0;
                  
                  return (
                    <ListGroup.Item
                      key={conv.id}
                      action
                      active={selectedConversationId === conv.id}
                      onClick={() => handleSelectConversation(conv.id)}
                      className={`d-flex justify-content-between align-items-start ${unreadMessageCount > 0 ? 'therapist-unread-conversation' : ''}`}
                    >
                      <div className="flex-grow-1">
                        <div className="d-flex justify-content-between align-items-center mb-1">
                          <div className="d-flex align-items-center">
                            <strong className={unreadMessageCount > 0 ? 'font-weight-bold' : ''}>
                              {conv.subject_name || 'Unknown'}
                            </strong>
                            {/* Unread message count badge - prominent display */}
                            {unreadMessageCount > 0 && (
                              <Badge variant="primary" className="ml-2">
                                {unreadMessageCount} new
                              </Badge>
                            )}
                          </div>
                          <div className="d-flex gap-1">
                            {features.showRiskColumn && getRiskBadge(conv.risk_level)}
                            {features.showStatusColumn && getStatusBadge(conv.status)}
                            {(conv.unread_alerts ?? 0) > 0 && (
                              <Badge variant="danger" className="ml-1">
                                <i className="fas fa-exclamation-triangle mr-1"></i>
                                {conv.unread_alerts}
                              </Badge>
                            )}
                            {(conv.pending_tags ?? 0) > 0 && (
                              <Badge variant="warning" className="ml-1">
                                <i className="fas fa-at"></i>
                              </Badge>
                            )}
                          </div>
                        </div>
                        <small className={unreadMessageCount > 0 ? 'text-dark' : 'text-muted'}>
                          {conv.subject_code} • {conv.message_count ?? 0} messages
                          {conv.ai_enabled ? '' : ' • 🤖❌'}
                        </small>
                      </div>
                    </ListGroup.Item>
                  );
                })
              )}
            </ListGroup>
          </Card>
        </Col>

        {/* Main Chat Area */}
        <Col md={8} lg={6}>
          <Card className="border-0 shadow-sm h-100 d-flex flex-column">
            {selectedConversationId && conversation ? (
              <>
                {/* Conversation Header */}
                <Card.Header className="bg-white d-flex justify-content-between align-items-center">
                  <div>
                    <h5 className="mb-0">{conversation.subject_name || labels.subjectLabel}</h5>
                    <small className="text-muted">
                      {conversation.subject_code}
                      {conversation.ai_enabled 
                        ? <span className="ml-2 text-success"><i className="fas fa-robot mr-1"></i>{labels.aiModeIndicator}</span>
                        : <span className="ml-2 text-warning"><i className="fas fa-user-md mr-1"></i>{labels.humanModeIndicator}</span>
                      }
                    </small>
                  </div>
                  <div className="d-flex align-items-center gap-2">
                    {features.showRiskColumn && getRiskBadge(conversation.risk_level)}
                    {features.showStatusColumn && getStatusBadge(conversation.status)}
                    
                    {/* AI Toggle */}
                    {features.enableAiToggle && (
                      <Button
                        variant={conversation.ai_enabled ? 'outline-warning' : 'outline-success'}
                        size="sm"
                        onClick={handleToggleAI}
                        title={conversation.ai_enabled ? labels.disableAI : labels.enableAI}
                      >
                        <i className="fas fa-robot mr-1"></i>
                        {conversation.ai_enabled ? labels.disableAI : labels.enableAI}
                      </Button>
                    )}
                    
                    {/* Status Control Dropdown */}
                    {features.enableStatusControl && (
                      <Dropdown>
                        <Dropdown.Toggle variant="outline-secondary" size="sm" id="status-dropdown">
                          <i className="fas fa-flag mr-1"></i>
                        </Dropdown.Toggle>
                        <Dropdown.Menu>
                          <Dropdown.Item onClick={() => handleSetStatus('active')}>
                            <Badge variant="success" className="mr-2">●</Badge> {labels.statusActive}
                          </Dropdown.Item>
                          <Dropdown.Item onClick={() => handleSetStatus('paused')}>
                            <Badge variant="warning" className="mr-2">●</Badge> {labels.statusPaused}
                          </Dropdown.Item>
                          <Dropdown.Item onClick={() => handleSetStatus('closed')}>
                            <Badge variant="secondary" className="mr-2">●</Badge> {labels.statusClosed}
                          </Dropdown.Item>
                        </Dropdown.Menu>
                      </Dropdown>
                    )}
                  </div>
                </Card.Header>

                {/* Error */}
                {error && (
                  <Alert variant="danger" dismissible onClose={clearError} className="m-3 mb-0">
                    {error}
                  </Alert>
                )}

                {/* Messages */}
                <Card.Body className="p-0 flex-grow-1 d-flex flex-column overflow-hidden">
                  <MessageList
                    messages={messages}
                    isLoading={isLoading}
                    labels={labels}
                    isTherapistView={true}
                  />
                  
                  {isSending && (
                    <div className="px-3 pb-2">
                      <LoadingIndicator text={labels.loading} />
                    </div>
                  )}
                </Card.Body>

                {/* Input */}
                <Card.Footer className="bg-white">
                  <MessageInput
                    onSend={sendMessage}
                    disabled={isSending || isLoading}
                    placeholder={labels.sendPlaceholder}
                    buttonLabel={labels.sendButton}
                  />
                </Card.Footer>
              </>
            ) : (
              <Card.Body className="d-flex align-items-center justify-content-center text-muted">
                <div className="text-center">
                  <i className="fas fa-hand-pointer fa-3x mb-3 opacity-50"></i>
                  <p>{labels.selectConversation}</p>
                </div>
              </Card.Body>
            )}
          </Card>
        </Col>

        {/* Notes & Controls Sidebar */}
        <Col lg={3} className="d-none d-lg-block">
          {selectedConversationId && conversation && (
            <div className="d-flex flex-column gap-3">
              {/* Risk Controls */}
              {features.enableRiskControl && (
                <Card className="border-0 shadow-sm">
                  <Card.Header className="bg-light">
                    <h6 className="mb-0">
                      <i className="fas fa-shield-alt mr-2"></i>
                      {labels.riskHeading}
                    </h6>
                  </Card.Header>
                  <Card.Body className="p-2">
                    <div className="d-flex flex-wrap gap-1">
                      {(['low', 'medium', 'high', 'critical'] as RiskLevel[]).map((risk) => {
                        const riskLabels: Record<RiskLevel, string> = {
                          low: labels.riskLow,
                          medium: labels.riskMedium,
                          high: labels.riskHigh,
                          critical: labels.riskCritical,
                        };
                        const variants: Record<RiskLevel, string> = {
                          low: conversation.risk_level === risk ? 'success' : 'outline-success',
                          medium: conversation.risk_level === risk ? 'warning' : 'outline-warning',
                          high: conversation.risk_level === risk ? 'danger' : 'outline-danger',
                          critical: conversation.risk_level === risk ? 'danger' : 'outline-danger',
                        };
                        return (
                          <Button
                            key={risk}
                            variant={variants[risk]}
                            size="sm"
                            onClick={() => handleSetRisk(risk)}
                          >
                            {riskLabels[risk] || risk}
                          </Button>
                        );
                      })}
                    </div>
                  </Card.Body>
                </Card>
              )}

              {/* Notes */}
              {features.enableNotes && features.showNotesPanel && (
                <Card className="border-0 shadow-sm flex-grow-1">
                  <Card.Header className="bg-light">
                    <h6 className="mb-0">
                      <i className="fas fa-sticky-note mr-2"></i>
                      {labels.notesHeading}
                    </h6>
                  </Card.Header>
                  <Card.Body className="p-2 therapist-notes-list">
                    {notes.length === 0 ? (
                      <p className="text-muted text-center mb-0">No notes yet.</p>
                    ) : (
                      notes.map((note) => (
                        <div key={note.id} className="therapist-note-item mb-2 p-2">
                          <div className="d-flex justify-content-between text-muted mb-1">
                            <small>{note.author_name}</small>
                            <small>{new Date(note.created_at).toLocaleDateString()}</small>
                          </div>
                          <p className="mb-0 small">{note.content}</p>
                        </div>
                      ))
                    )}
                  </Card.Body>
                  <Card.Footer className="bg-white p-2">
                    <Form.Control
                      as="textarea"
                      rows={2}
                      value={newNote}
                      onChange={(e) => setNewNote(e.target.value)}
                      placeholder={labels.addNotePlaceholder}
                      className="mb-2"
                    />
                    <Button
                      variant="outline-primary"
                      size="sm"
                      onClick={handleAddNote}
                      disabled={!newNote.trim()}
                    >
                      <i className="fas fa-plus mr-1"></i>
                      {labels.addNoteButton}
                    </Button>
                  </Card.Footer>
                </Card>
              )}

              {/* Quick Actions */}
              <Card className="border-0 shadow-sm">
                <Card.Header className="bg-light">
                  <h6 className="mb-0">
                    <i className="fas fa-bolt mr-2"></i>
                    Quick Actions
                  </h6>
                </Card.Header>
                <Card.Body className="p-2">
                  <div className="d-grid gap-2">
                    <Button
                      variant="outline-primary"
                      size="sm"
                      onClick={() => {
                        // Open in LLM console
                        window.open(`/admin/llm?conversation=${conversation.id}`, '_blank');
                      }}
                    >
                      <i className="fas fa-external-link-alt mr-1"></i>
                      {labels.viewInLlm}
                    </Button>
                    {conversation.ai_enabled ? (
                      <Button
                        variant="outline-info"
                        size="sm"
                        onClick={() => {
                          handleToggleAI();
                          // Could send intervention message
                        }}
                      >
                        <i className="fas fa-user-md mr-1"></i>
                        {labels.joinConversation}
                      </Button>
                    ) : (
                      <Button
                        variant="outline-success"
                        size="sm"
                        onClick={() => {
                          handleToggleAI();
                        }}
                      >
                        <i className="fas fa-robot mr-1"></i>
                        {labels.leaveConversation}
                      </Button>
                    )}
                  </div>
                </Card.Body>
              </Card>
            </div>
          )}
        </Col>
      </Row>
    </Container>
  );
};

export default TherapistDashboard;
