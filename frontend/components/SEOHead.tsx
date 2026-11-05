import React, { useEffect } from 'react';
import { useLocation } from 'react-router';

interface SEOHeadProps {
  title: string;
  description?: string;
  image?: string;
}

// Crea la etiqueta <meta> si no existe (Laravel ya las manda en la primera carga)
const setMeta = (attr: 'name' | 'property', key: string, content?: string) => {
  let meta = document.querySelector(`meta[${attr}="${key}"]`);
  if (!meta) {
    meta = document.createElement('meta');
    meta.setAttribute(attr, key);
    document.head.appendChild(meta);
  }
  if (content) {
    meta.setAttribute('content', content);
  }
};

const SEOHead: React.FC<SEOHeadProps> = ({ title, description, image }) => {
  const { pathname } = useLocation();

  useEffect(() => {
    document.title = title;
    setMeta('name', 'description', description);
    setMeta('property', 'og:title', title);
    setMeta('property', 'og:description', description);
    setMeta('property', 'og:image', image);
    setMeta('property', 'og:url', window.location.origin + pathname);
  }, [title, description, image, pathname]);

  return null;
};

export default SEOHead;
