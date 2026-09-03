import type { Meta, StoryObj } from '@storybook/react-vite';
import { Button, Panel, StatusPill } from './index';

const meta = { title: 'Clínica DEZ/Design System', component: Button } satisfies Meta<typeof Button>;
export default meta;
type Story = StoryObj<typeof meta>;

export const Acoes: Story = { render: () => <Panel style={{ padding: 24, display: 'flex', gap: 12, flexWrap: 'wrap' }}><Button>Novo agendamento</Button><Button variant="success">Fazer check-in</Button><Button variant="warning">Resolver</Button><Button variant="purple">Enviar mensagem</Button><Button variant="danger">Cancelar</Button></Panel> };
export const Estados: Story = { render: () => <Panel style={{ padding: 24, display: 'flex', gap: 12 }}><StatusPill tone="success">Confirmado</StatusPill><StatusPill tone="warning">Aguardando</StatusPill><StatusPill tone="purple">Teleconsulta</StatusPill><StatusPill tone="danger">Cancelado</StatusPill></Panel> };
