declare module '@wordpress/element' {
  import type { ReactNode } from 'react';
  export * from 'react';
  export const createRoot: (container: Element | DocumentFragment) => {
    render: (reactNode: ReactNode) => void;
    unmount: () => void;
  };
  export const render: (reactNode: ReactNode, container: Element | DocumentFragment) => void;
}
