import type { Meta, StoryObj } from '@storybook/react-vite'
import { ErrorBoundary } from './ErrorBoundary'

function Boom(): never {
  throw new Error('boom')
}

const meta = {
  title: 'Components/ErrorBoundary',
  component: ErrorBoundary,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component:
          'App.tsxのルート直下に1つだけ配置する最終防衛ライン。子要素のレンダー時例外を捕捉し、白画面ではなく再読み込みを促すフォールバックを表示する。',
      },
    },
  },
} satisfies Meta<typeof ErrorBoundary>

export default meta
type Story = StoryObj<typeof meta>

export const NoError: Story = {
  args: {
    children: <p>正常に描画されている子要素</p>,
  },
}

export const CaughtError: Story = {
  args: {
    children: <Boom />,
  },
}
