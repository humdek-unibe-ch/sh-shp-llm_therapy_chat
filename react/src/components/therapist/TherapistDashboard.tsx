/**
 * Therapist Dashboard Component
 * ==============================
 * 
 * Dashboard for therapists to monitor and communicate with patients.
 * Features:
 * - Conversation list with filtering
 * - Real-time message viewing
 * - Alert management
 * - Notes and controls
 */

import React, { useState, useEffect, useCallback } from 'react';
import { Container, Row, Col, Card, ListGroup, Badge, Button, Form, Alert } from 'react-bootstrap';
import { MessageList } from '../shared/MessageList';
import { MessageInput } from '../shared/MessageInput';
import { LoadingIndicator } from '../shared/LoadingIndicator';
import { useChatState } from '../../hooks/useChatState';
import { usePolling } from '../../hooks/usePolling';
import { therapistDashboardApi } from '../../utils/api';
import type { TherapistDashboardConfig, Conversation, Alert as AlertType, Tag, Note, RiskLevel } from '../../types';
import './TherapistDashboard.css';

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
   * Load conversations list
   */
  const loadConversations = useCallback(async () => {
    setIsLoadingList(true);
    try {
      const response = await therapistDashboardApi.getConversations(config.sectionId);
      setConversations(response.conversations || []);
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to load conversations';
      setListError(message);
    } finally {
      setIsLoadingList(false);
    }
  }, [config.sectionId]);

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
  }, [loadConversations, loadAlerts, loadTags]);

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
   * Set up polling for alerts and messages
   */
  usePolling({
    callback: async () => {
      await loadAlerts();
      await loadTags();
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
  const handleSelectConversation = useCallback((convId: number | string) => {
    setSelectedConversationId(convId);
  }, []);

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
    if (!conversation?.id) return;

    try {
      await therapistDashboardApi.setRiskLevel(config.sectionId, conversation.id, riskLevel);
      loadConversation(conversation.id);
      loadConversations();
    } catch (err) {
      console.error('Set risk error:', err);
    }
  }, [config.sectionId, conversation, loadConversation, loadConversations]);

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
    
    return (
      <Badge variant={variants[risk]} className={risk === 'critical' ? 'font-weight-bold' : ''}>
        {risk === 'critical' && <i className="fas fa-exclamation-triangle mr-1"></i>}
        {config.labels[`risk${risk.charAt(0).toUpperCase() + risk.slice(1)}` as keyof typeof config.labels] || risk}
      </Badge>
    );
  };

  return (
    <Container fluid className="therapist-dashboard py-3">
      {/* Header with Stats */}
      <Row className="mb-3">
        <Col>
          <Card className="border-0 shadow-sm">
            <Card.Body className="d-flex justify-content-between align-items-center">
              <h4 className="mb-0">
                <i className="fas fa-stethoscope text-primary mr-2"></i>
                {config.labels.title}
              </h4>
              <div className="d-flex gap-4">
                <div className="text-center">
                  <div className="h4 mb-0">{config.stats.total}</div>
                  <small className="text-muted">Patients</small>
                </div>
                <div className="text-center">
                  <div className="h4 mb-0 text-success">{config.stats.active}</div>
                  <small className="text-muted">Active</small>
                </div>
                <div className="text-center">
                  <div className="h4 mb-0 text-danger">{config.stats.risk_critical}</div>
                  <small className="text-muted">Critical</small>
                </div>
                <div className="text-center">
                  <div className="h4 mb-0 text-warning">{alerts.length}</div>
                  <small className="text-muted">Alerts</small>
                </div>
                <div className="text-center">
                  <div className="h4 mb-0 text-info">{tags.length}</div>
                  <small className="text-muted">Tags</small>
                </div>
              </div>
            </Card.Body>
          </Card>
        </Col>
      </Row>

      {/* Alerts Banner */}
      {(alerts.length > 0 || tags.length > 0) && (
        <Row className="mb-3">
          <Col>
            {alerts.filter(a => a.severity === 'critical' || a.severity === 'emergency').map((alert) => (
              <Alert key={alert.id} variant="danger" className="mb-2 d-flex justify-content-between align-items-center">
                <div>
                  <i className="fas fa-exclamation-triangle mr-2"></i>
                  <strong>{alert.subject_name}:</strong> {alert.message}
                </div>
                <Button variant="outline-light" size="sm" onClick={() => handleMarkAlertRead(alert.id)}>
                  <i className="fas fa-check mr-1"></i> Dismiss
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
                  <i className="fas fa-check mr-1"></i> Acknowledge
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
              <h6 className="mb-0">
                <i className="fas fa-comments mr-2"></i>
                {config.labels.conversationsHeading}
              </h6>
            </Card.Header>
            <ListGroup variant="flush" className="therapist-conversation-list">
              {isLoadingList ? (
                <div className="p-3 text-center text-muted">
                  <div className="spinner-border spinner-border-sm" role="status">
                    <span className="sr-only">Loading...</span>
                  </div>
                </div>
              ) : listError ? (
                <div className="p-3 text-center text-danger">{listError}</div>
              ) : conversations.length === 0 ? (
                <div className="p-3 text-center text-muted">{config.labels.noConversations}</div>
              ) : (
                conversations.map((conv) => (
                  <ListGroup.Item
                    key={conv.id}
                    action
                    active={selectedConversationId === conv.id}
                    onClick={() => handleSelectConversation(conv.id)}
                    className="d-flex justify-content-between align-items-start"
                  >
                    <div className="flex-grow-1">
                      <div className="d-flex justify-content-between align-items-center mb-1">
                        <strong>{conv.subject_name || 'Unknown'}</strong>
                        <div>
                          {getRiskBadge(conv.risk_level)}
                          {(conv.unread_alerts ?? 0) > 0 && (
                            <Badge variant="danger" className="ml-1">{conv.unread_alerts}</Badge>
                          )}
                          {(conv.pending_tags ?? 0) > 0 && (
                            <Badge variant="warning" className="ml-1">
                              <i className="fas fa-at"></i>
                            </Badge>
                          )}
                        </div>
                      </div>
                      <small className="text-muted">
                        {conv.subject_code} • {conv.message_count ?? 0} messages
                      </small>
                    </div>
                  </ListGroup.Item>
                ))
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
                    <h5 className="mb-0">{conversation.subject_name || 'Patient'}</h5>
                    <small className="text-muted">{conversation.subject_code}</small>
                  </div>
                  <div className="d-flex align-items-center gap-2">
                    {getRiskBadge(conversation.risk_level)}
                    <Button
                      variant={conversation.ai_enabled ? 'outline-secondary' : 'outline-primary'}
                      size="sm"
                      onClick={handleToggleAI}
                    >
                      <i className="fas fa-robot mr-1"></i>
                      {conversation.ai_enabled ? config.labels.disableAI : config.labels.enableAI}
                    </Button>
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
                    labels={config.labels}
                    isTherapistView={true}
                  />
                  
                  {isSending && (
                    <div className="px-3 pb-2">
                      <LoadingIndicator text={config.labels.loading} />
                    </div>
                  )}
                </Card.Body>

                {/* Input */}
                <Card.Footer className="bg-white">
                  <MessageInput
                    onSend={sendMessage}
                    disabled={isSending || isLoading}
                    labels={config.labels}
                  />
                </Card.Footer>
              </>
            ) : (
              <Card.Body className="d-flex align-items-center justify-content-center text-muted">
                <div className="text-center">
                  <i className="fas fa-hand-pointer fa-3x mb-3 opacity-50"></i>
                  <p>{config.labels.selectConversation}</p>
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
              <Card className="border-0 shadow-sm">
                <Card.Header className="bg-light">
                  <h6 className="mb-0">
                    <i className="fas fa-shield-alt mr-2"></i>
                    Risk Level
                  </h6>
                </Card.Header>
                <Card.Body className="p-2">
                  <div className="d-flex flex-wrap gap-1">
                    {(['low', 'medium', 'high', 'critical'] as RiskLevel[]).map((risk) => (
                      <Button
                        key={risk}
                        variant={conversation.risk_level === risk ? 'primary' : 'outline-secondary'}
                        size="sm"
                        onClick={() => handleSetRisk(risk)}
                      >
                        {config.labels[`risk${risk.charAt(0).toUpperCase() + risk.slice(1)}` as keyof typeof config.labels] || risk}
                      </Button>
                    ))}
                  </div>
                </Card.Body>
              </Card>

              {/* Notes */}
              <Card className="border-0 shadow-sm flex-grow-1">
                <Card.Header className="bg-light">
                  <h6 className="mb-0">
                    <i className="fas fa-sticky-note mr-2"></i>
                    {config.labels.notesHeading}
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
                    placeholder={config.labels.addNotePlaceholder}
                    className="mb-2"
                  />
                  <Button
                    variant="outline-primary"
                    size="sm"
                    onClick={handleAddNote}
                    disabled={!newNote.trim()}
                  >
                    <i className="fas fa-plus mr-1"></i>
                    {config.labels.addNoteButton}
                  </Button>
                </Card.Footer>
              </Card>
            </div>
          )}
        </Col>
      </Row>
    </Container>
  );
};

export default TherapistDashboard;
