import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { Ms365CredentialsFields, type Ms365CredentialsFieldsValue } from './Ms365CredentialsFields'

function StatefulFields(props: { required?: boolean; clientSecretConfigured?: boolean }) {
  const [value, setValue] = useState<Ms365CredentialsFieldsValue>({
    tenantId: '',
    clientId: '',
    clientSecret: '',
    redirectUri: 'http://localhost:8000/api/auth/microsoft/callback',
    mockEnabled: false,
  })

  return (
    <div className="max-w-lg">
      <Ms365CredentialsFields idPrefix="story" value={value} onChange={setValue} {...props} />
    </div>
  )
}

const meta = {
  title: 'Components/Ms365CredentialsFields',
  component: StatefulFields,
  tags: ['autodocs'],
} satisfies Meta<typeof StatefulFields>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Required: Story = {
  args: { required: true },
}

export const SecretAlreadyConfigured: Story = {
  args: { clientSecretConfigured: true },
}
