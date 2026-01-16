/**
 * KnowledgeBase Component
 *
 * Main routing component for Knowledge Base management.
 */

import { Routes, Route, Navigate } from 'react-router-dom';
import KBOverview from './KBOverview';
import KBDocuments from './KBDocuments';
import KBTestSearch from './KBTestSearch';
import KBSettings from './KBSettings';
import KBLogs from './KBLogs';

/**
 * KnowledgeBase main component with nested routing.
 *
 * @param {Object} props - Component props.
 * @param {Object} props.vibeAiData - Localized data from WordPress.
 * @returns {JSX.Element} KnowledgeBase element.
 */
export default function KnowledgeBase({ vibeAiData }) {
  return (
    <Routes>
      <Route path="/" element={<KBOverview vibeAiData={vibeAiData} />} />
      <Route path="/documents" element={<KBDocuments vibeAiData={vibeAiData} />} />
      <Route path="/search" element={<KBTestSearch vibeAiData={vibeAiData} />} />
      <Route path="/settings" element={<KBSettings vibeAiData={vibeAiData} />} />
      <Route path="/logs" element={<KBLogs vibeAiData={vibeAiData} />} />
      <Route path="*" element={<Navigate to="/kb" replace />} />
    </Routes>
  );
}
