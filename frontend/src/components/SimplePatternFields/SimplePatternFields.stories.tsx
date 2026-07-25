import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { defaultSimplePatternState, SimplePatternFields, type SimplePatternState } from './SimplePatternFields'

function Demo() {
  const [state, setState] = useState<SimplePatternState>(defaultSimplePatternState())

  return <SimplePatternFields state={state} onChange={(patch) => setState((prev) => ({ ...prev, ...patch }))} />
}

const meta = {
  title: 'Components/SimplePatternFields',
  component: SimplePatternFields,
} satisfies Meta<typeof SimplePatternFields>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => <Demo />,
}
