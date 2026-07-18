import type { Meta, StoryObj } from '@storybook/react-vite'
import { Card, CardHeader, CardTitle, CardDescription, CardAction, CardContent, CardFooter } from './card'
import { Button } from './button'

const meta = {
  title: 'UI/Card',
  component: Card,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component: 'shadcn/ui相当の内部実装プリミティブ(CardHeader/CardTitle/CardAction等)。ページ実装では直接使わず、`components/Card`を使うこと。',
      },
    },
  },
} satisfies Meta<typeof Card>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => (
    <Card className="w-96">
      <CardHeader>
        <div>
          <CardTitle>月次勤怠(2026年7月)</CardTitle>
          <CardDescription>締め日: 7月31日</CardDescription>
        </div>
        <CardAction>
          <Button size="sm">提出</Button>
        </CardAction>
      </CardHeader>
      <CardContent className="text-sm text-muted-foreground">総労働時間: 168時間00分</CardContent>
      <CardFooter>
        <Button variant="secondary" size="sm">
          詳細を見る
        </Button>
      </CardFooter>
    </Card>
  ),
}
