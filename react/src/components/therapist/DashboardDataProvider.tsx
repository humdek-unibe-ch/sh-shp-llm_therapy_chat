/**
 * Dashboard Data Provider
 * =======================
 *
 * Centralized data management for the therapist dashboard.
 * Handles all API calls, state management, and data synchronization.
 */

import React, { createContext, useContext, useReducer, useCallback, useEffect, useRef } from 'react';
import { createTherapistApi } from '../../utils/api';
import { getTotalUnread } from '../../utils/unreadHelpers';
import { updateFloatingBadge } from '../../utils/floatingBadge';
import type {
  TherapistDashboardConfig,
  Conversation,
  Alert,
  Note,
  UnreadCounts,
  TherapistGroup,
  Draft,
  DashboardStats,
} from '../../types';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface DashboardState {
  // Data
  conversations: Conversation[];
  alerts: Alert[];
  notes: Note[];
  unreadCounts: UnreadCounts;
  groups: TherapistGroup[];
  stats: DashboardStats | null;
  
  // UI State
  selectedConversationId: number | string | null;
  activeGroupId: number | string | null;
  activeFilter: 'all' | 'active' | 'critical' | 'unread';
  
  // Loading states
  loading: {
    conversations: boolean;
    alerts: boolean;
    notes: boolean;
    stats: boolean;
    unreadCounts: boolean;
  };
  
  // Error states
  errors: {
    conversations: string | null;
    alerts: string | null;
    notes: string | null;
    stats: string | null;
    unreadCounts: string | null;
  };
}

type DashboardAction =
  | { type: 'SET_CONVERSATIONS'; payload: Conversation[] }
  | { type: 'SET_ALERTS'; payload: Alert[] }
  | { type: 'SET_NOTES'; payload: Note[] }
  | { type: 'SET_UNREAD_COUNTS'; payload: UnreadCounts }
  | { type: 'SET_GROUPS'; payload: TherapistGroup[] }
  | { type: 'SET_STATS'; payload: DashboardStats }
  | { type: 'SET_SELECTED_CONVERSATION'; payload: number | string | null }
  | { type: 'SET_ACTIVE_GROUP'; payload: number | string | null }
  | { type: 'SET_ACTIVE_FILTER'; payload: 'all' | 'active' | 'critical' | 'unread' }
  | { type: 'SET_LOADING'; payload: { key: keyof DashboardState['loading']; value: boolean } }
  | { type: 'SET_ERROR'; payload: { key: keyof DashboardState['errors']; value: string | null } }
  | { type: 'ADD_NOTE'; payload: Note }
  | { type: 'UPDATE_NOTE'; payload: { id: number; note: Partial<Note> } }
  | { type: 'DELETE_NOTE'; payload: number }
  | { type: 'UPDATE_CONVERSATION'; payload: { id: number | string; conversation: Partial<Conversation> } }
  | { type: 'ADD_ALERT'; payload: Alert }
  | { type: 'MARK_ALERT_READ'; payload: number };

interface DashboardContextValue {
  state: DashboardState;
  actions: {
    // Data fetching
    loadConversations: (groupId?: number | string | null, filter?: string, silent?: boolean) => Promise<void>;
    loadAlerts: () => Promise<void>;
    loadNotes: (conversationId: number | string) => Promise<void>;
    loadUnreadCounts: () => Promise<void>;
    loadStats: () => Promise<void>;
    loadGroups: () => Promise<void>;
    
    // State management
    setSelectedConversation: (id: number | string | null) => void;
    setActiveGroup: (id: number | string | null) => void;
    setActiveFilter: (filter: 'all' | 'active' | 'critical' | 'unread') => void;
    
    // CRUD operations
    addNote: (note: Note) => void;
    updateNote: (id: number, note: Partial<Note>) => void;
    deleteNote: (id: number) => void;
    updateConversation: (id: number | string, conversation: Partial<Conversation>) => void;
    markAlertRead: (alertId: number) => void;
    
    // Utility
    refresh: () => Promise<void>;
  };
}

// ---------------------------------------------------------------------------
// Context
// ---------------------------------------------------------------------------

const DashboardContext = createContext<DashboardContextValue | null>(null);

export const useDashboardData = () => {
  const context = useContext(DashboardContext);
  if (!context) {
    throw new Error('useDashboardData must be used within DashboardDataProvider');
  }
  return context;
};

// ---------------------------------------------------------------------------
// Reducer
// ---------------------------------------------------------------------------

const initialState: DashboardState = {
  conversations: [],
  alerts: [],
  notes: [],
  unreadCounts: { total: 0, totalAlerts: 0, bySubject: {} },
  groups: [],
  stats: null,
  selectedConversationId: null,
  activeGroupId: null,
  activeFilter: 'all',
  loading: {
    conversations: false,
    alerts: false,
    notes: false,
    stats: false,
    unreadCounts: false,
  },
  errors: {
    conversations: null,
    alerts: null,
    notes: null,
    stats: null,
    unreadCounts: null,
  },
};

function dashboardReducer(state: DashboardState, action: DashboardAction): DashboardState {
  switch (action.type) {
    case 'SET_CONVERSATIONS':
      return { ...state, conversations: action.payload };
    
    case 'SET_ALERTS':
      return { ...state, alerts: action.payload };
    
    case 'SET_NOTES':
      return { ...state, notes: action.payload };
    
    case 'SET_UNREAD_COUNTS':
      return { ...state, unreadCounts: action.payload };
    
    case 'SET_GROUPS':
      return { ...state, groups: action.payload };
    
    case 'SET_STATS':
      return { ...state, stats: action.payload };
    
    case 'SET_SELECTED_CONVERSATION':
      return { ...state, selectedConversationId: action.payload };
    
    case 'SET_ACTIVE_GROUP':
      return { ...state, activeGroupId: action.payload };
    
    case 'SET_ACTIVE_FILTER':
      return { ...state, activeFilter: action.payload };
    
    case 'SET_LOADING':
      return {
        ...state,
        loading: { ...state.loading, [action.payload.key]: action.payload.value },
      };
    
    case 'SET_ERROR':
      return {
        ...state,
        errors: { ...state.errors, [action.payload.key]: action.payload.value },
      };
    
    case 'ADD_NOTE':
      return { ...state, notes: [...state.notes, action.payload] };
    
    case 'UPDATE_NOTE':
      return {
        ...state,
        notes: state.notes.map(note =>
          note.id === action.payload.id ? { ...note, ...action.payload.note } : note
        ),
      };
    
    case 'DELETE_NOTE':
      return {
        ...state,
        notes: state.notes.filter(note => note.id !== action.payload),
      };
    
    case 'UPDATE_CONVERSATION':
      return {
        ...state,
        conversations: state.conversations.map(conv =>
          conv.id === action.payload.id ? { ...conv, ...action.payload.conversation } : conv
        ),
      };
    
    case 'ADD_ALERT':
      return { ...state, alerts: [...state.alerts, action.payload] };
    
    case 'MARK_ALERT_READ':
      return {
        ...state,
        alerts: state.alerts.map(alert =>
          alert.id === action.payload ? { ...alert, is_read: true } : alert
        ),
      };
    
    default:
      return state;
  }
}

// ---------------------------------------------------------------------------
// Provider Component
// ---------------------------------------------------------------------------

interface DashboardDataProviderProps {
  children: React.ReactNode;
  config: TherapistDashboardConfig;
  /** Initial group ID from URL (overrides config.selectedGroupId) */
  initialGroupId?: number | string | null;
  /** Initial subject/conversation ID from URL */
  initialSubjectId?: number | string | null;
}

export const DashboardDataProvider: React.FC<DashboardDataProviderProps> = ({
  children,
  config,
  initialGroupId,
  initialSubjectId,
}) => {
  // Resolve effective initial values: URL takes priority, then config
  const effectiveGroupId = initialGroupId ?? config.selectedGroupId ?? null;
  const effectiveSubjectId = initialSubjectId ?? config.selectedSubjectId ?? null;

  const [state, dispatch] = useReducer(dashboardReducer, {
    ...initialState,
    activeGroupId: effectiveGroupId,
    selectedConversationId: effectiveSubjectId,
  });
  const apiRef = useRef(createTherapistApi(config.sectionId));
  const api = apiRef.current;

  // ---------------------------------------------------------------------------
  // Data loading actions
  // ---------------------------------------------------------------------------

  const loadConversations = useCallback(async (
    groupId?: number | string | null,
    filter?: string,
    silent = false
  ) => {
    if (!silent) {
      dispatch({ type: 'SET_LOADING', payload: { key: 'conversations', value: true } });
      dispatch({ type: 'SET_ERROR', payload: { key: 'conversations', value: null } });
    }

    try {
      const filters: Record<string, string | number> = {};
      if (groupId != null) filters.group_id = groupId;
      if (filter && filter !== 'all') filters.filter = filter;

      const response = await api.getConversations(filters);
      dispatch({ type: 'SET_CONVERSATIONS', payload: response.conversations || [] });
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to load conversations';
      dispatch({ type: 'SET_ERROR', payload: { key: 'conversations', value: message } });
      if (!silent) console.error('Load conversations error:', err);
    } finally {
      if (!silent) {
        dispatch({ type: 'SET_LOADING', payload: { key: 'conversations', value: false } });
      }
    }
  }, [api]);

  const loadAlerts = useCallback(async () => {
    dispatch({ type: 'SET_LOADING', payload: { key: 'alerts', value: true } });
    dispatch({ type: 'SET_ERROR', payload: { key: 'alerts', value: null } });

    try {
      const response = await api.getAlerts(true);
      dispatch({ type: 'SET_ALERTS', payload: response.alerts || [] });
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to load alerts';
      dispatch({ type: 'SET_ERROR', payload: { key: 'alerts', value: message } });
    } finally {
      dispatch({ type: 'SET_LOADING', payload: { key: 'alerts', value: false } });
    }
  }, [api]);

  const loadNotes = useCallback(async (conversationId: number | string) => {
    dispatch({ type: 'SET_LOADING', payload: { key: 'notes', value: true } });
    dispatch({ type: 'SET_ERROR', payload: { key: 'notes', value: null } });

    try {
      const response = await api.getNotes(conversationId);
      dispatch({ type: 'SET_NOTES', payload: response.notes || [] });
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to load notes';
      dispatch({ type: 'SET_ERROR', payload: { key: 'notes', value: message } });
    } finally {
      dispatch({ type: 'SET_LOADING', payload: { key: 'notes', value: false } });
    }
  }, [api]);

  const loadUnreadCounts = useCallback(async () => {
    try {
      const response = await api.getUnreadCounts();
      const unreadCounts = response?.unread_counts || { total: 0, totalAlerts: 0, bySubject: {} };
      dispatch({ type: 'SET_UNREAD_COUNTS', payload: unreadCounts });
      updateFloatingBadge(getTotalUnread(unreadCounts));
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to load unread counts';
      dispatch({ type: 'SET_ERROR', payload: { key: 'unreadCounts', value: message } });
    }
  }, [api]);

  const loadStats = useCallback(async () => {
    dispatch({ type: 'SET_LOADING', payload: { key: 'stats', value: true } });

    try {
      const response = await api.getStats();
      dispatch({ type: 'SET_STATS', payload: response.stats });
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to load stats';
      dispatch({ type: 'SET_ERROR', payload: { key: 'stats', value: message } });
    } finally {
      dispatch({ type: 'SET_LOADING', payload: { key: 'stats', value: false } });
    }
  }, [api]);

  const loadGroups = useCallback(async () => {
    try {
      const response = await api.getGroups();
      dispatch({ type: 'SET_GROUPS', payload: response.groups || [] });
    } catch (err) {
      console.error('Load groups error:', err);
    }
  }, [api]);

  // ---------------------------------------------------------------------------
  // State management actions
  // ---------------------------------------------------------------------------

  const setSelectedConversation = useCallback((id: number | string | null) => {
    dispatch({ type: 'SET_SELECTED_CONVERSATION', payload: id });
  }, []);

  const setActiveGroup = useCallback((id: number | string | null) => {
    dispatch({ type: 'SET_ACTIVE_GROUP', payload: id });
  }, []);

  const setActiveFilter = useCallback((filter: 'all' | 'active' | 'critical' | 'unread') => {
    dispatch({ type: 'SET_ACTIVE_FILTER', payload: filter });
  }, []);

  // ---------------------------------------------------------------------------
  // CRUD operations
  // ---------------------------------------------------------------------------

  const addNote = useCallback((note: Note) => {
    dispatch({ type: 'ADD_NOTE', payload: note });
  }, []);

  const updateNote = useCallback((id: number, note: Partial<Note>) => {
    dispatch({ type: 'UPDATE_NOTE', payload: { id, note } });
  }, []);

  const deleteNote = useCallback((id: number) => {
    dispatch({ type: 'DELETE_NOTE', payload: id });
  }, []);

  const updateConversation = useCallback((id: number | string, conversation: Partial<Conversation>) => {
    dispatch({ type: 'UPDATE_CONVERSATION', payload: { id, conversation } });
  }, []);

  const markAlertRead = useCallback(async (alertId: number) => {
    // Optimistic local update
    dispatch({ type: 'MARK_ALERT_READ', payload: alertId });
    // Persist to backend
    try {
      await api.markAlertRead(alertId);
    } catch (err) {
      console.error('Failed to mark alert as read:', err);
    }
  }, [api]);

  // ---------------------------------------------------------------------------
  // Utility actions
  // ---------------------------------------------------------------------------

  const refresh = useCallback(async () => {
    await Promise.all([
      loadConversations(state.activeGroupId, state.activeFilter, true),
      loadAlerts(),
      loadUnreadCounts(),
      loadStats(),
    ]);
  }, [state.activeGroupId, state.activeFilter, loadConversations, loadAlerts, loadUnreadCounts, loadStats]);

  // ---------------------------------------------------------------------------
  // Initial data loading (runs once on mount)
  // ---------------------------------------------------------------------------

  const didInit = useRef(false);
  useEffect(() => {
    if (didInit.current) return;
    didInit.current = true;

    // Seed groups from config (server-rendered)
    if (config.groups || config.assignedGroups) {
      dispatch({ type: 'SET_GROUPS', payload: config.groups || config.assignedGroups || [] });
    }
    // Seed stats from config so they show instantly
    if (config.stats) {
      dispatch({ type: 'SET_STATS', payload: config.stats });
    }

    // Fetch live data
    Promise.all([
      loadConversations(effectiveGroupId, 'all'),
      loadAlerts(),
      loadUnreadCounts(),
      loadStats(),
    ]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ---------------------------------------------------------------------------
  // Context value
  // ---------------------------------------------------------------------------

  const contextValue: DashboardContextValue = {
    state,
    actions: {
      loadConversations,
      loadAlerts,
      loadNotes,
      loadUnreadCounts,
      loadStats,
      loadGroups,
      setSelectedConversation,
      setActiveGroup,
      setActiveFilter,
      addNote,
      updateNote,
      deleteNote,
      updateConversation,
      markAlertRead,
      refresh,
    },
  };

  return (
    <DashboardContext.Provider value={contextValue}>
      {children}
    </DashboardContext.Provider>
  );
};

export default DashboardDataProvider;
