/**
 * AI Entity Index Admin App
 *
 * Main application component with routing and layout.
 */

import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { useState, useCallback } from 'react';
import Header from './components/Layout/Header';
import Sidebar from './components/Layout/Sidebar';
import Dashboard from './components/Dashboard';
import EntityManager from './components/EntityManager';
import EntityDrawer from './components/EntityDrawer';
import Settings from './components/Settings';
import ActivityLog from './components/ActivityLog';
import KnowledgeBase from './components/KnowledgeBase';

/**
 * Global data from WordPress (localized via wp_localize_script).
 * @type {{apiUrl: string, nonce: string, adminUrl: string, pluginUrl: string, version: string, pollingInterval: number}}
 */
const vibeAiData = window.vibeAiData || {
  apiUrl: '/wp-json/vibe-ai/v1',
  nonce: '',
  adminUrl: '/wp-admin/',
  pluginUrl: '',
  version: '1.0.0',
  pollingInterval: 2000,
};

/**
 * Main App Component
 *
 * Provides routing and layout structure for the admin interface.
 * Uses HashRouter for compatibility with WordPress admin.
 */
export default function App() {
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [selectedEntityId, setSelectedEntityId] = useState(null);

  const toggleSidebar = useCallback(() => {
    setSidebarCollapsed((prev) => !prev);
  }, []);

  const handleEntitySelect = useCallback((entityId) => {
    setSelectedEntityId(entityId);
  }, []);

  const handleEntityClose = useCallback(() => {
    setSelectedEntityId(null);
  }, []);

  return (
    <HashRouter>
      <div id="vibe-ai-admin" className="flex flex-col min-h-screen bg-slate-50">
        {/* Header */}
        <Header
          onToggleSidebar={toggleSidebar}
          vibeAiData={vibeAiData}
        />

        {/* Main content area */}
        <div className="flex flex-1">
          {/* Sidebar navigation */}
          <Sidebar collapsed={sidebarCollapsed} />

          {/* Page content */}
          <main
            className={`flex-1 transition-all duration-300 ${
              sidebarCollapsed ? 'ml-16' : 'ml-56'
            }`}
          >
            <Routes>
              <Route path="/" element={<Navigate to="/dashboard" replace />} />
              <Route
                path="/dashboard"
                element={<Dashboard vibeAiData={vibeAiData} />}
              />
              <Route
                path="/entities"
                element={
                  <EntityManager
                    vibeAiData={vibeAiData}
                    onEntitySelect={handleEntitySelect}
                  />
                }
              />
              <Route
                path="/entities/:id"
                element={
                  <EntityManager
                    vibeAiData={vibeAiData}
                    onEntitySelect={handleEntitySelect}
                  />
                }
              />
              <Route
                path="/settings"
                element={<Settings vibeAiData={vibeAiData} />}
              />
              <Route
                path="/logs"
                element={<ActivityLog vibeAiData={vibeAiData} />}
              />
              <Route path="/kb/*" element={<KnowledgeBase vibeAiData={vibeAiData} />} />
              {/* Catch-all redirect */}
              <Route path="*" element={<Navigate to="/dashboard" replace />} />
            </Routes>
          </main>

          {/* Entity Drawer (slide-over panel) */}
          {selectedEntityId && (
            <EntityDrawer
              entityId={selectedEntityId}
              onClose={handleEntityClose}
              vibeAiData={vibeAiData}
            />
          )}
        </div>
      </div>
    </HashRouter>
  );
}

// Export vibeAiData for use in other modules
export { vibeAiData };
