/**
 * AI Entity Index Admin App
 *
 * Main application component with routing and layout.
 */

import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { useState, useCallback } from 'react';
import Header from './components/Layout/Header';
import Sidebar from './components/Layout/Sidebar';
import Dashboard from './components/Dashboard';

/**
 * Global data from WordPress (localized via wp_localize_script).
 * @type {{apiUrl: string, nonce: string, adminUrl: string, pluginUrl: string}}
 */
const vibeAiData = window.vibeAiData || {
  apiUrl: '/wp-json/vibe-ai/v1',
  nonce: '',
  adminUrl: '/wp-admin/',
  pluginUrl: '',
};

// Placeholder components for routes not yet implemented
const EntitiesPage = () => (
  <div className="p-6">
    <h1 className="text-2xl font-bold text-slate-800 mb-4">Entities</h1>
    <p className="text-slate-600">Entity management interface coming soon.</p>
  </div>
);

const EntityDetailPage = () => (
  <div className="p-6">
    <h1 className="text-2xl font-bold text-slate-800 mb-4">Entity Details</h1>
    <p className="text-slate-600">Entity detail view coming soon.</p>
  </div>
);

const SettingsPage = () => (
  <div className="p-6">
    <h1 className="text-2xl font-bold text-slate-800 mb-4">Settings</h1>
    <p className="text-slate-600">Settings panel coming soon.</p>
  </div>
);

const LogsPage = () => (
  <div className="p-6">
    <h1 className="text-2xl font-bold text-slate-800 mb-4">Logs</h1>
    <p className="text-slate-600">Full log viewer coming soon.</p>
  </div>
);

/**
 * Main App Component
 *
 * Provides routing and layout structure for the admin interface.
 */
export default function App() {
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);

  const toggleSidebar = useCallback(() => {
    setSidebarCollapsed((prev) => !prev);
  }, []);

  // Get the base path for the admin page
  const basePath = vibeAiData.adminUrl
    ? `${vibeAiData.adminUrl}admin.php?page=ai-entity-index`
    : '/wp-admin/admin.php?page=ai-entity-index';

  return (
    <BrowserRouter>
      <div className="flex flex-col min-h-screen bg-slate-50">
        {/* Header */}
        <Header
          onToggleSidebar={toggleSidebar}
          vibeAiData={vibeAiData}
        />

        {/* Main content area */}
        <div className="flex flex-1">
          {/* Sidebar navigation */}
          <Sidebar
            collapsed={sidebarCollapsed}
            basePath={basePath}
          />

          {/* Page content */}
          <main
            className={`flex-1 transition-all duration-300 ${
              sidebarCollapsed ? 'ml-16' : 'ml-56'
            }`}
          >
            <Routes>
              <Route path="/" element={<Navigate to="/dashboard" replace />} />
              <Route path="/dashboard" element={<Dashboard vibeAiData={vibeAiData} />} />
              <Route path="/entities" element={<EntitiesPage />} />
              <Route path="/entities/:id" element={<EntityDetailPage />} />
              <Route path="/settings" element={<SettingsPage />} />
              <Route path="/logs" element={<LogsPage />} />
              {/* Catch-all redirect */}
              <Route path="*" element={<Navigate to="/dashboard" replace />} />
            </Routes>
          </main>
        </div>
      </div>
    </BrowserRouter>
  );
}

// Export vibeAiData for use in other modules
export { vibeAiData };
