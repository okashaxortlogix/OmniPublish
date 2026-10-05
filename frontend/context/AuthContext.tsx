'use client';

import React, { createContext, useContext, useEffect, useState } from 'react';
import { api, setStoredAuthToken, setStoredWorkspaceId, getStoredWorkspaceId } from '@/lib/api';

export interface UserProfile {
  id: number;
  name: string;
  email: string;
  avatar?: string;
  role?: string;
}

export interface WorkspaceInfo {
  id: string;
  name: string;
  slug: string;
}

interface AuthContextType {
  user: UserProfile | null;
  workspace: WorkspaceInfo | null;
  loading: boolean;
  refreshUser: () => Promise<void>;
  updateUser: (name: string, email: string) => Promise<void>;
  logout: () => void;
}

const AuthContext = createContext<AuthContextType>({
  user: null,
  workspace: null,
  loading: true,
  refreshUser: async () => {},
  updateUser: async () => {},
  logout: () => {},
});

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<UserProfile | null>(null);
  const [workspace, setWorkspace] = useState<WorkspaceInfo | null>(null);
  const [loading, setLoading] = useState(true);

  const refreshUser = async () => {
    try {
      const data = await api.getMe();
      if (data && data.user) {
        setUser({
          ...data.user,
          avatar: `https://ui-avatars.com/api/?name=${encodeURIComponent(data.user.name)}&background=2563eb&color=fff&size=150`,
        });
      }
      if (data && data.currentWorkspace) {
        setWorkspace(data.currentWorkspace);
        setStoredWorkspaceId(data.currentWorkspace.slug || data.currentWorkspace.id);
      }
    } catch (err) {
      // Fallback local profile if offline or error
      const cached = localStorage.getItem('omnipublish_user');
      if (cached) {
        try {
          setUser(JSON.parse(cached));
        } catch (e) {
          // ignore
        }
      } else {
        setUser({
          id: 1,
          name: 'Alex Vance',
          email: 'alex@omnipublish.io',
          avatar: 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=150',
          role: 'Workspace Owner',
        });
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    refreshUser();
  }, []);

  const updateUser = async (name: string, email: string) => {
    const updated = await api.updateProfile(name, email);
    if (updated) {
      const newUser = {
        ...user,
        ...updated,
        avatar: `https://ui-avatars.com/api/?name=${encodeURIComponent(updated.name)}&background=2563eb&color=fff&size=150`,
      };
      setUser(newUser);
      localStorage.setItem('omnipublish_user', JSON.stringify(newUser));
    }
  };

  const logout = () => {
    setStoredAuthToken(null);
    localStorage.removeItem('omnipublish_user');
    setUser(null);
    if (typeof window !== 'undefined') {
      window.location.href = '/login';
    }
  };

  return (
    <AuthContext.Provider value={{ user, workspace, loading, refreshUser, updateUser, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}
