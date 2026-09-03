import type { Preview } from '@storybook/react-vite';
import '../packages/ui/src/styles.css';

const preview: Preview = {
  parameters: {
    backgrounds: { default: 'Aplicação', values: [{ name: 'Aplicação', value: '#f2f8fa' }, { name: 'Branco', value: '#ffffff' }] },
    controls: { expanded: true },
  },
};

export default preview;
